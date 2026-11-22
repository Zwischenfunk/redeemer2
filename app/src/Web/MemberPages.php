<?php

namespace App\Web;

use App\Member\Actor;
use App\Member\Denied;
use App\Member\MemberAccounts;
use App\Member\MemberPolicy;
use App\Member\MemberQueries;
use App\Member\NotFound;
use App\Member\Outcome;
use App\Member\Permission;
use App\Member\Profile;
use App\Member\RoleDelegation;
use App\Member\SearchFilter;
use App\Member\Status;
use App\Security\Impersonation;
use App\Security\MemberIdentity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/** `?section=member` for logged-in members: profile, create, search, delete and identity switch. */
final readonly class MemberPages
{
    public const SEARCH_SESSION_KEY = 'member_search';

    public function __construct(
        private Environment $twig,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private MemberAccounts $accounts,
        private MemberQueries $queries,
        private MemberPolicy $policy,
        private RoleDelegation $roles,
        private Impersonation $impersonation,
    ) {
    }

    public function handle(Request $request, MemberIdentity $identity): Response
    {
        $actor = Actor::from($identity);
        $action = (string) $request->query->get('action', '');

        try {
            return match ($action) {
                'update' => $this->update($request, $actor),
                'insert' => $this->insert($request, $actor),
                'search' => $this->search($request, $actor),
                'delete' => $this->delete($request, $actor),
                default => throw new NotFoundHttpException(),
            };
        } catch (Denied) {
            throw new AccessDeniedHttpException();
        } catch (NotFound) {
            throw new NotFoundHttpException();
        }
    }

    private function update(Request $request, Actor $actor): Response
    {
        $id = $this->targetId($request, $actor->effectiveId);
        $permission = $this->policy->forExisting($actor, $id) ?? throw new Denied();
        $current = $this->queries->profile($id) ?? throw new NotFound();

        if (!$request->isMethod('POST')) {
            $this->assertMethod($request, ['GET', 'HEAD']);

            return $this->form($actor, $id, $current, $permission);
        }

        $post = $request->request->all();
        if ('go' === ($post['transform'] ?? null)) {
            return $this->transform($request, $actor, $id);
        }
        $this->assertToken($request, 'member_save');
        $outcome = $this->accounts->update($actor, $id, $post);
        $profile = $outcome->isDone()
            ? ($this->queries->profile($id) ?? $current)
            : $current->with($this->submitted($post, $permission));

        return $this->form($actor, $id, $profile, $permission, $outcome, $post['roles'] ?? null);
    }

    private function transform(Request $request, Actor $actor, int $targetId): Response
    {
        if (!$this->policy->mayTransform($actor)) {
            throw new Denied();
        }
        $this->assertToken($request, 'member_transform');
        if ($targetId === $actor->originalId) {
            $this->impersonation->stop();
        } else {
            $this->impersonation->start($targetId);
        }

        return new RedirectResponse($this->urls->generate('app_home', ['section' => 'nodes']), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'private, no-store']);
    }

    private function insert(Request $request, Actor $actor): Response
    {
        if (!$this->policy->mayCreate($actor)) {
            throw new Denied();
        }
        $permission = $this->policy->insertPermission();
        if (!$request->isMethod('POST')) {
            $this->assertMethod($request, ['GET', 'HEAD']);

            return $this->form($actor, null, new Profile('', '', '', '', '', '', '', '', '', '', '', ''), $permission);
        }

        $this->assertToken($request, 'member_create');
        $post = $request->request->all();
        $outcome = $this->accounts->create($actor, $post);
        if (Status::Rejected === $outcome->status) {
            return $this->form($actor, null, Profile::fromArray($this->submitted($post, $permission)), $permission, $outcome);
        }

        return $this->result($outcome, 'Benutzer anlegen', $outcome->memberId);
    }

    private function delete(Request $request, Actor $actor): Response
    {
        if (!$this->policy->mayDelete($actor)) {
            throw new Denied();
        }
        $id = $this->targetId($request, null);
        $profile = $this->queries->profile($id) ?? throw new NotFound();

        if (!$request->isMethod('POST')) {
            $this->assertMethod($request, ['GET', 'HEAD']);

            return $this->page('member/delete_confirm.html.twig', ['id' => $id, 'member' => $profile]);
        }

        $this->assertToken($request, 'member_delete');

        return $this->result($this->accounts->delete($actor, $id), 'Benutzer löschen');
    }

    private function search(Request $request, Actor $actor): Response
    {
        if (!$this->policy->maySearch($actor)) {
            throw new Denied();
        }
        $session = $request->getSession();
        $errors = [];
        $messages = [];
        $filter = SearchFilter::fromArray((array) $session->get(self::SEARCH_SESSION_KEY, []));
        $input = null;

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'member_search');
            $post = $request->request->all();
            $term = $post['search'] ?? '';
            $in = SearchFilter::parseGroups(is_string($post['ingroups'] ?? '') ? ($post['ingroups'] ?? '') : '-');
            $off = SearchFilter::parseGroups(is_string($post['offgroups'] ?? '') ? ($post['offgroups'] ?? '') : '-');
            if (!is_string($term) || 1 === preg_match('/[\x00-\x1f\x7f]/', $term) || mb_strlen($term) > 100) {
                $errors['search'] = 'Ungültiger Suchbegriff.';
            }
            if (null === $in) {
                $errors['ingroups'] = 'Nur kommagetrennte Rollen-IDs erlaubt.';
            }
            if (null === $off) {
                $errors['offgroups'] = 'Nur kommagetrennte Rollen-IDs erlaubt.';
            }
            $filter = new SearchFilter(is_string($term) ? trim($term) : '', $in ?? [], $off ?? [], isset($post['limitresult']));
            if ([] !== $errors) {
                $messages[] = 'Bitte die markierten Felder korrigieren.';
                $input = $post;

                return $this->page('member/search.html.twig', ['filter' => $filter, 'rows' => null, 'errors' => $errors, 'messages' => $messages, 'raw' => $input], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $session->set(self::SEARCH_SESSION_KEY, $filter->toArray());

            return new RedirectResponse($request->getRequestUri(), Response::HTTP_MOVED_PERMANENTLY, ['Cache-Control' => 'private, no-store']);
        }

        $this->assertMethod($request, ['GET', 'HEAD']);
        $rows = null;
        if ($filter->isSpecific()) {
            $rows = $this->queries->search($filter, $actor->isAdmin());
        } elseif ('' !== $filter->term) {
            $messages[] = 'Such-String zu kurz';
        }

        return $this->page('member/search.html.twig', [
            'filter' => $filter, 'rows' => $rows, 'errors' => [], 'messages' => $messages, 'raw' => null,
            'mayDelete' => $this->policy->mayDelete($actor), 'roleNames' => $this->queries->roleNames(),
            'limit' => $filter->limited ? 30 : 150,
        ]);
    }

    private function form(Actor $actor, ?int $id, Profile $profile, Permission $permission, ?Outcome $outcome = null, mixed $postedRoles = null): Response
    {
        $groups = [];
        if (null !== $id) {
            $held = $this->queries->roleIds($id);
            $names = $this->queries->roleNames();
            foreach ($this->roles->groupsFor($actor, $id) as $key => $group) {
                $selected = 0;
                foreach ($group['ids'] as $roleId) {
                    if (in_array($roleId, $held, true)) {
                        $selected = $roleId;
                        break;
                    }
                }
                if (is_array($postedRoles) && is_string($postedRoles[$key] ?? null) && !$outcome?->isDone()) {
                    $selected = (int) $postedRoles[$key];
                }
                $groups[$key] = ['label' => $group['label'], 'ids' => $group['ids'], 'selected' => $selected, 'names' => array_intersect_key($names, array_flip($group['ids']))];
            }
        }
        $status = match ($outcome?->status) {
            null, Status::Done => Response::HTTP_OK,
            Status::Rejected => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_GATEWAY,
        };

        return $this->page('member/form.html.twig', [
            'id' => $id,
            'member' => $profile->toArray(),
            'editable' => $permission->fields,
            'password' => $permission->password,
            'groups' => $groups,
            'outcome' => $outcome,
            'errors' => null === $outcome ? [] : $outcome->errors,
            'transformable' => null !== $id && $this->policy->mayTransform($actor) && $id !== $actor->effectiveId,
            'insert' => null === $id,
        ], $status);
    }

    private function result(Outcome $outcome, string $title, ?int $memberId = null): Response
    {
        $status = match ($outcome->status) {
            Status::Done => Response::HTTP_OK,
            Status::Rejected => Response::HTTP_CONFLICT,
            default => Response::HTTP_BAD_GATEWAY,
        };

        return $this->page('member/result.html.twig', ['outcome' => $outcome, 'title' => $title, 'memberId' => $memberId], $status);
    }

    /** @param array<string, mixed> $parameters */
    private function page(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render($template, $parameters), $status, ['Cache-Control' => 'private, no-store']);
    }

    private function targetId(Request $request, ?int $default): int
    {
        $raw = $request->query->get('id');
        if (null === $raw && null !== $default) {
            return $default;
        }
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

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, string>
     */
    private function submitted(array $post, Permission $permission): array
    {
        $values = [];
        foreach ($permission->fields as $field) {
            if (is_string($post[$field] ?? null)) {
                $values[$field] = trim($post[$field]);
            }
        }

        return $values;
    }
}
