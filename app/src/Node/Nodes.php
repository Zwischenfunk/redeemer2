<?php

namespace App\Node;

use App\Member\Actor;
use App\Member\Denied;
use App\Member\NotFound;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * Create, change and delete nodes and give the Tech-C role back, through the unchanged database routines.
 * Writes to an existing node check rights and preconditions on the locked node (see NodeAccess).
 * Creation uses one routine call; there is no existing node to lock.
 */
final readonly class Nodes
{
    public function __construct(private Connection $db, private NodeAccess $access, private NodeRules $rules)
    {
    }

    /**
     * The actor becomes owner and Tech-C.
     *
     * @param array<string, mixed> $input
     *
     * @throws Rejected
     */
    public function create(Actor $actor, array $input): int
    {
        $values = $this->rules->validated($input);
        try {
            return (int) $this->db->fetchOne('SELECT insertnode(?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->arguments($actor->effectiveId, $values), [8 => ParameterType::BOOLEAN]);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws NotFound
     * @throws Denied
     * @throws Rejected
     */
    public function update(Actor $actor, int $nodeId, array $input): void
    {
        try {
            $this->db->transactional(function () use ($actor, $nodeId, $input): void {
                $this->access->managed($actor, $nodeId, lock: true);
                $this->db->executeStatement('SELECT updatenode(?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->arguments($nodeId, $this->rules->validated($input)), [8 => ParameterType::BOOLEAN]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    /**
     * The node if the actor may delete it now: owner or Tech-C, and nothing hangs on the node.
     *
     * @throws NotFound
     * @throws Denied
     * @throws Rejected
     */
    public function deletable(Actor $actor, int $nodeId, bool $lock = false): Node
    {
        $node = $this->access->managed($actor, $nodeId, $lock);
        $used = $this->db->fetchOne(
            'SELECT EXISTS (SELECT FROM devices WHERE id_nodes = :node) OR EXISTS (SELECT FROM ip6 WHERE id_nodes = :node)',
            ['node' => $nodeId],
        );

        return $used ? throw new Rejected('Devices vorhanden: Löschen nicht möglich. Bitte zuerst alle Devices und IPv6-Adressen des Nodes entfernen.') : $node;
    }

    /** Deletes the node and its pending handover proposal. */
    public function delete(Actor $actor, int $nodeId): Node
    {
        return $this->db->transactional(function () use ($actor, $nodeId): Node {
            $node = $this->deletable($actor, $nodeId, lock: true);
            $this->db->executeStatement('DELETE FROM nodes WHERE id = ?', [-$nodeId]);
            $this->db->executeStatement('SELECT deletenode(?)', [$nodeId]);

            return $node;
        });
    }

    /**
     * The node if the actor (owner or Tech-C) may end its separate Tech-C assignment.
     *
     * @throws NotFound
     * @throws Denied
     * @throws Rejected
     */
    public function resignable(Actor $actor, int $nodeId, bool $lock = false): Node
    {
        $node = $this->access->managed($actor, $nodeId, $lock);

        return $node->hasSeparateTechC() ? $node : throw new Rejected('Der Besitzer ist bereits Tech-Contact dieses Nodes.');
    }

    /** The owner becomes Tech-C again; the ownership does not change. */
    public function resign(Actor $actor, int $nodeId): Node
    {
        return $this->db->transactional(function () use ($actor, $nodeId): Node {
            $node = $this->resignable($actor, $nodeId, lock: true);
            $this->db->executeStatement('UPDATE nodes SET id_tech_c = id_members WHERE id = ?', [$nodeId]);

            return $node;
        });
    }

    /**
     * @param array{name: string, map: bool, coordinates: array<string, float|null>} $values
     *
     * @return list<mixed> arguments of insertnode (first: owner) and updatenode (first: node)
     */
    private function arguments(int $first, array $values): array
    {
        return [$first, $values['name'], ...array_values($values['coordinates']), $values['map']];
    }

    private function nameTaken(): Rejected
    {
        return Rejected::fields(['name' => 'Dieser Name ist bereits vergeben.']);
    }
}
