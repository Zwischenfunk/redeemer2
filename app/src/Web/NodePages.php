<?php

namespace App\Web;

use App\Member\Actor;
use App\Member\Denied;
use App\Member\NotFound;
use App\Node\Handover;
use App\Node\Node;
use App\Node\NodeAccess;
use App\Node\NodeQueries;
use App\Node\NodeRules;
use App\Node\Nodes;
use App\Node\Proposal;
use App\Node\ProposalKind;
use App\Node\Rejected;
use App\Security\MemberIdentity;
use Doctrine\DBAL\Exception as DatabaseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * `?section=nodes` for logged-in members: overview, create, change, delete and handover of nodes.
 * GET only reads; delete, resign, withdraw, accept and decline show a confirmation form on GET. Every write is
 * a POST with a CSRF token.
 */
final readonly class NodePages
{
    public function __construct(
        private Environment $twig,
        private CsrfTokenManagerInterface $csrf,
        private LoggerInterface $logger,
        private NodeAccess $access,
        private NodeQueries $queries,
        private Nodes $nodes,
        private Handover $handover,
    ) {
    }

    public function handle(Request $request, MemberIdentity $identity): Response
    {
        $actor = Actor::from($identity);
        $action = (string) $request->query->get('action', '');
        // Legacy links of the owner to take a proposal back: resign or decline with p=<member>.
        if (in_array($action, ['resign', 'decline'], true) && !in_array($request->query->get('p', ''), ['', '0'], true)) {
            $action = 'withdraw';
        }

        try {
            return match ($action) {
                '' => $this->overview($request, $actor),
                'insert' => $this->insert($request, $actor),
                'update' => $this->update($request, $actor),
                'proposeOwner' => $this->propose($request, $actor, ProposalKind::Owner),
                'proposeTechC' => $this->propose($request, $actor, ProposalKind::TechC),
                'delete', 'resign', 'withdraw', 'accept', 'decline' => $this->confirmed($request, $actor, $action),
                default => throw new NotFoundHttpException(),
            };
        } catch (Denied) {
            throw new AccessDeniedHttpException();
        } catch (NotFound) {
            throw new NotFoundHttpException();
        } catch (DatabaseException $exception) {
            $this->logger->error('Node database failure: {message}', ['message' => $exception->getMessage()]);

            return $this->result('failed', ['Die Datenbank hat die Anfrage nicht ausgeführt. Es wurde nichts geändert.'], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function overview(Request $request, Actor $actor): Response
    {
        $this->assertMethod($request, ['GET', 'HEAD']);
        // The legacy list takes id_members, but only for one's own id.
        if (!in_array($request->query->get('id_members'), [null, (string) $actor->effectiveId], true)) {
            throw new Denied();
        }

        $owned = $this->queries->owned($actor->effectiveId);
        $techC = $this->queries->techContactOf($actor->effectiveId);
        $incoming = $this->queries->incoming($actor->effectiveId);
        $memberIds = [];
        foreach ($owned as $row) {
            array_push($memberIds, $row['node']->techCId, ...(null === $row['proposal'] ? [] : [$row['proposal']->recipientId]));
        }
        foreach ([...array_column($techC, 'node'), ...array_column($incoming, 'node')] as $node) {
            $memberIds[] = $node->ownerId;
        }

        return $this->page('node/list.html.twig', [
            'owned' => $owned, 'techC' => $techC, 'incoming' => $incoming, 'contacts' => $this->queries->contacts($memberIds),
        ]);
    }

    private function insert(Request $request, Actor $actor): Response
    {
        if (!$request->isMethod('POST')) {
            $this->assertMethod($request, ['GET', 'HEAD']);

            return $this->form(null, ['name' => '', 'map' => true] + array_fill_keys(Node::COORDINATES, ''));
        }

        $this->assertToken($request, 'node_insert');
        $post = $request->request->all();
        $values = $this->submitted($post);
        try {
            $this->nodes->create($actor, $post);
        } catch (Rejected $rejected) {
            return $this->form(null, $values, $rejected);
        }

        return $this->result('done', [sprintf('Der Node "%s" wurde angelegt.', $values['name'])]);
    }

    private function update(Request $request, Actor $actor): Response
    {
        $node = $this->access->managed($actor, $this->nodeId($request));
        if (!$request->isMethod('POST')) {
            $this->assertMethod($request, ['GET', 'HEAD']);
            $coordinates = array_map(static fn (?float $value) => null === $value ? '' : (string) $value, $node->coordinates);

            return $this->form($node, ['name' => $node->name, 'map' => $node->map] + $coordinates);
        }

        $this->assertToken($request, 'node_update');
        $post = $request->request->all();
        $values = $this->submitted($post);
        try {
            $this->nodes->update($actor, $node->id, $post);
        } catch (Rejected $rejected) {
            return $this->form($node, $values, $rejected);
        }

        return $this->result('done', [sprintf('Der Node "%s" wurde geändert.', $values['name'])]);
    }

    private function propose(Request $request, Actor $actor, ProposalKind $kind): Response
    {
        $node = $this->access->owned($actor, $this->nodeId($request));
        $parameters = ['node' => $node, 'kind' => $kind, 'pending' => null !== $this->handover->pending($node), 'input' => '', 'stay' => true, 'error' => null];
        if (!$request->isMethod('POST')) {
            $this->assertMethod($request, ['GET', 'HEAD']);

            return $this->page('node/propose.html.twig', $parameters);
        }

        $this->assertToken($request, 'node_propose');
        $post = $request->request->all();
        try {
            $made = $this->handover->propose($actor, $node->id, $kind, $post);
        } catch (Rejected $rejected) {
            return $this->page('node/propose.html.twig', [
                'input' => is_string($post['id_tech_c'] ?? null) ? trim($post['id_tech_c']) : '',
                'stay' => NodeRules::checked($post['stay_tech_c'] ?? null),
                'error' => $rejected->errors['id_tech_c'] ?? $rejected->getMessage(),
            ] + $parameters, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->result('done', [
            sprintf(ProposalKind::Owner === $kind ? 'Der Vorschlag, den Node "%s" weiterzugeben, wurde gespeichert.' : 'Die Einladung als Tech-Contact des Nodes "%s" wurde gespeichert.', $node->name),
            ...($made['replaced'] ? ['Der bisherige Vorschlag für diesen Node wurde dadurch ersetzt.'] : []),
        ], recipient: $made['recipient']);
    }

    private function confirmed(Request $request, Actor $actor, string $action): Response
    {
        $id = $this->nodeId($request);
        $posted = $request->isMethod('POST');
        if ($posted) {
            $this->assertToken($request, 'node_'.$action);
        } else {
            $this->assertMethod($request, ['GET', 'HEAD']);
        }
        $seen = $request->request->all()['proposal'] ?? null;
        $seen = is_string($seen) ? $seen : '';

        try {
            $subject = match ($action) {
                'delete' => $posted ? $this->nodes->delete($actor, $id) : $this->nodes->deletable($actor, $id),
                'resign' => $posted ? $this->nodes->resign($actor, $id) : $this->nodes->resignable($actor, $id),
                'withdraw' => $posted ? $this->handover->withdraw($actor, $id, $seen) : $this->handover->withdrawable($actor, $id),
                'accept' => $posted ? $this->handover->accept($actor, $id, $seen) : $this->handover->decidable($actor, $id),
                'decline' => $posted ? $this->handover->decline($actor, $id, $seen) : $this->handover->decidable($actor, $id),
                default => throw new \LogicException('Unknown action '.$action),
            };
        } catch (Rejected $rejected) {
            return $this->result('rejected', [$rejected->getMessage()], Response::HTTP_CONFLICT);
        }
        [$node, $proposal] = $subject instanceof Proposal ? [$subject->node, $subject] : [$subject, null];

        if (!$posted) {
            return $this->page('node/confirm.html.twig', [
                'action' => $action, 'node' => $node, 'proposal' => $proposal,
                'contacts' => $this->queries->contacts([$node->ownerId, $node->techCId, ...(null === $proposal ? [] : [$proposal->recipientId])]),
            ]);
        }

        return $this->result('done', [sprintf(match ($action) {
            'delete' => 'Der Node "%s" wurde gelöscht.',
            'resign' => 'Der Besitzer ist wieder Tech-Contact des Nodes "%s".',
            'withdraw' => 'Der Vorschlag für den Node "%s" wurde zurückgezogen.',
            'accept' => $proposal?->transfersOwnership() ? 'Du bist jetzt Besitzer des Nodes "%s".' : 'Du bist jetzt Tech-Contact des Nodes "%s".',
            default => 'Der Vorschlag für den Node "%s" wurde abgelehnt.',
        }, $node->name)]);
    }

    /** @param array<string, mixed> $values name, map and the coordinate fields as entered */
    private function form(?Node $node, array $values, ?Rejected $rejected = null): Response
    {
        return $this->page('node/form.html.twig', [
            'node' => $node, 'values' => $values, 'errors' => $rejected->errors ?? [], 'message' => $rejected?->getMessage(),
        ], null === $rejected ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param list<string>                                                     $messages
     * @param array{firstname: string, lastname: string, email: string}|null $recipient of a new proposal
     */
    private function result(string $status, array $messages, int $code = Response::HTTP_OK, ?array $recipient = null): Response
    {
        return $this->page('node/result.html.twig', ['status' => $status, 'messages' => $messages, 'recipient' => $recipient], $code);
    }

    /** @param array<string, mixed> $parameters */
    private function page(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render($template, $parameters), $status, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{name: string, map: bool}&array<string, string|bool> the form values to show again
     */
    private function submitted(array $post): array
    {
        $values = ['name' => '', 'map' => NodeRules::checked($post['map'] ?? null)];
        foreach (['name', ...Node::COORDINATES] as $field) {
            $values[$field] = is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        }

        return $values;
    }

    /** Nodes have positive ids; a proposal row is never addressed by its negative id. */
    private function nodeId(Request $request): int
    {
        $raw = $request->query->get('id');
        $id = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return false === $id ? throw new NotFoundHttpException() : $id;
    }

    private function assertToken(Request $request, string $id): void
    {
        $token = $request->request->all()['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken($id, $token))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /** @param list<string> $allowed */
    private function assertMethod(Request $request, array $allowed): void
    {
        if (!in_array($request->getMethod(), $allowed, true)) {
            throw new MethodNotAllowedHttpException($allowed);
        }
    }
}
