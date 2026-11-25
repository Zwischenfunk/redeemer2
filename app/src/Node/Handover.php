<?php

namespace App\Node;

use App\Member\Actor;
use App\Member\Denied;
use App\Member\NotFound;
use Doctrine\DBAL\Connection;

/**
 * Handing a node or its Tech-C role to another member in two steps: the owner proposes (or withdraws), the
 * addressed member accepts or declines. Nobody else takes part, and the application sends no mail.
 * Every write checks the roles and the proposal inside its transaction, on the locked node (see NodeAccess).
 */
final readonly class Handover
{
    private const GONE = 'Diesen Vorschlag gibt es nicht mehr: Er wurde bereits angenommen, abgelehnt, zurückgezogen oder ersetzt.';

    public function __construct(private Connection $db, private NodeAccess $access)
    {
    }

    public function pending(Node $node, bool $lock = false): ?Proposal
    {
        $row = $this->db->fetchAssociative(
            'SELECT id_members, id_tech_c, ROW(xmin, ctid, created)::text AS row_version FROM nodes WHERE id = ?'.($lock ? ' FOR UPDATE' : ''),
            [-$node->id],
        );

        return false === $row ? null : Proposal::fromRow($node, $row);
    }

    /**
     * The owner proposes a new owner or Tech-C; an earlier proposal for the node is replaced.
     *
     * @param array<string, mixed> $input recipient_type: id, email or nickname; id_tech_c: the lookup value; stay_tech_c: the owner stays Tech-C
     *
     * @return array{recipient: array{id: int, firstname: string, lastname: string, email: string}, replaced: bool}
     *
     * @throws NotFound
     * @throws Denied
     * @throws Rejected
     */
    public function propose(Actor $actor, int $nodeId, ProposalKind $kind, array $input): array
    {
        return $this->db->transactional(function () use ($actor, $nodeId, $kind, $input): array {
            $node = $this->access->owned($actor, $nodeId, lock: true);
            $recipient = $this->recipient($input['recipient_type'] ?? null, $input['id_tech_c'] ?? null);
            $error = match (true) {
                $recipient['id'] === $node->ownerId && ProposalKind::Owner === $kind => 'Dieser Benutzer ist bereits Besitzer des Nodes.',
                $recipient['id'] === $node->ownerId => 'Der Besitzer wird Tech-Contact, indem er den bisherigen Tech-Contact entfernt.',
                $recipient['id'] === $node->techCId && ProposalKind::TechC === $kind => 'Dieser Benutzer ist bereits Tech-Contact des Nodes.',
                default => null,
            };
            if (null !== $error) {
                throw Rejected::fields(['id_tech_c' => $error]);
            }

            [$owner, $techC] = match ($kind) {
                ProposalKind::Owner => [$recipient['id'], NodeRules::checked($input['stay_tech_c'] ?? null) ? $node->ownerId : $recipient['id']],
                ProposalKind::TechC => [$node->ownerId, $recipient['id']],
            };
            $replaced = $this->db->executeStatement('DELETE FROM nodes WHERE id = ?', [-$nodeId]) > 0;
            $this->db->executeStatement(
                'INSERT INTO nodes (id, name, map, id_members, id_tech_c) VALUES (?, ?, false, ?, ?)',
                [-$nodeId, (string) -$nodeId, $owner, $techC],
            );

            return ['recipient' => $recipient, 'replaced' => $replaced];
        });
    }

    /**
     * The pending proposal if the actor, the node's owner, may withdraw it.
     *
     * @param string|null $version the version the actor decided on; null only looks
     *
     * @throws NotFound
     * @throws Denied
     * @throws Rejected
     */
    public function withdrawable(Actor $actor, int $nodeId, ?string $version = null, bool $lock = false): Proposal
    {
        return $this->seen($this->pendingOrGone($this->access->owned($actor, $nodeId, $lock), $lock), $version);
    }

    public function withdraw(Actor $actor, int $nodeId, string $version): Proposal
    {
        return $this->db->transactional(function () use ($actor, $nodeId, $version): Proposal {
            $proposal = $this->withdrawable($actor, $nodeId, $version, lock: true);
            $this->remove($proposal);

            return $proposal;
        });
    }

    /**
     * The pending proposal if it is addressed to the actor, who alone accepts or declines it.
     *
     * @param string|null $version the version the actor decided on; null only looks
     *
     * @throws NotFound
     * @throws Denied
     * @throws Rejected
     */
    public function decidable(Actor $actor, int $nodeId, ?string $version = null, bool $lock = false): Proposal
    {
        $proposal = $this->pendingOrGone($this->access->node($nodeId, $lock), $lock);
        if ($proposal->recipientId !== $actor->effectiveId) {
            throw new Denied();
        }

        return $this->seen($proposal, $version);
    }

    public function accept(Actor $actor, int $nodeId, string $version): Proposal
    {
        return $this->db->transactional(function () use ($actor, $nodeId, $version): Proposal {
            $proposal = $this->decidable($actor, $nodeId, $version, lock: true);
            $this->db->executeStatement('UPDATE nodes SET id_members = ?, id_tech_c = ? WHERE id = ?', [$proposal->ownerId(), $proposal->techCId, $nodeId]);
            $this->remove($proposal);

            return $proposal;
        });
    }

    public function decline(Actor $actor, int $nodeId, string $version): Proposal
    {
        return $this->db->transactional(function () use ($actor, $nodeId, $version): Proposal {
            $proposal = $this->decidable($actor, $nodeId, $version, lock: true);
            $this->remove($proposal);

            return $proposal;
        });
    }

    private function pendingOrGone(Node $node, bool $lock): Proposal
    {
        return $this->pending($node, $lock) ?? throw new Rejected(self::GONE);
    }

    /** A decision counts only for the proposal the actor saw. */
    private function seen(Proposal $proposal, ?string $version): Proposal
    {
        return null !== $version && $version !== $proposal->version() ? throw new Rejected(self::GONE) : $proposal;
    }

    private function remove(Proposal $proposal): void
    {
        $this->db->executeStatement('DELETE FROM nodes WHERE id = ?', [-$proposal->node->id]);
    }

    /**
     * @return array{id: int, firstname: string, lastname: string, email: string}
     *
     * @throws Rejected
     */
    private function recipient(mixed $type, mixed $input): array
    {
        // A numeric nickname can also be another member's ID. Use only the selected field.
        [$condition, $what] = match ($type) {
            'id' => ['id = ?', 'dieser ID'],
            'nickname' => ['lower(nickname) = lower(?)', 'diesem Nickname'],
            'email' => ['lower(email) = lower(?)', 'dieser E-Mail-Adresse'],
            default => throw Rejected::fields(['recipient_type' => 'Bitte Member-ID, Nickname oder E-Mail-Adresse als Eingabetyp auswählen.']),
        };
        $input = is_string($input) ? trim($input) : '';
        if ('' === $input || strlen($input) > 100 || 1 === preg_match('/[\x00-\x1f\x7f]/', $input) || !mb_check_encoding($input, 'UTF-8')) {
            throw Rejected::fields(['id_tech_c' => 'Bitte Member-ID, Nickname oder E-Mail-Adresse des Benutzers angeben.']);
        }
        $value = $input;
        if ('id' === $type) {
            $value = ctype_digit($input) ? filter_var(ltrim($input, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if (false === $value) {
                throw Rejected::fields(['id_tech_c' => 'Bitte eine positive Member-ID angeben.']);
            }
        }
        $rows = $this->db->fetchAllAssociative('SELECT id, firstname, lastname, email FROM members WHERE id > 0 AND '.$condition.' ORDER BY id LIMIT 2', [$value]);
        if (1 !== count($rows)) {
            throw Rejected::fields(['id_tech_c' => [] === $rows
                ? sprintf('Kein Benutzer mit %s gefunden.', $what)
                : 'Diese E-Mail-Adresse gehört zu mehreren Benutzern. Bitte Member-ID oder Nickname angeben.']);
        }

        return ['id' => (int) $rows[0]['id'], 'firstname' => (string) $rows[0]['firstname'], 'lastname' => (string) $rows[0]['lastname'], 'email' => (string) $rows[0]['email']];
    }
}
