<?php

namespace App\Node;

use App\Member\Actor;
use App\Member\Denied;
use App\Member\NotFound;
use Doctrine\DBAL\Connection;

/**
 * The shared way to a node for everything that hangs on one (nodes here, devices and addresses in #9).
 *
 * Rights: the owner (nodes.id_members) and the Tech-C (nodes.id_tech_c) manage a node; only the owner hands it
 * over. Admins have no extra rights; object rights follow Actor::effectiveId. Only positive ids are nodes: rows
 * with a negative id are handover proposals and are never addressed, listed or counted as nodes.
 *
 * Writes to an existing node: open a transaction, fetch the node with lock: true, check, write, commit. The row
 * lock (FOR UPDATE) makes concurrent writers of the same node wait, so the check and the write see the same
 * owner, Tech-C and dependants. Lock the node first, rows that depend on it afterwards.
 */
final readonly class NodeAccess
{
    public function __construct(private Connection $db)
    {
    }

    /** @throws NotFound */
    public function node(int $nodeId, bool $lock = false): Node
    {
        if ($lock && !$this->db->isTransactionActive()) {
            throw new \LogicException('A node is locked for a write; open the transaction first.');
        }
        $row = $nodeId > 0 ? $this->db->fetchAssociative('SELECT * FROM nodes WHERE id = ?'.($lock ? ' FOR UPDATE' : ''), [$nodeId]) : false;

        return false === $row ? throw new NotFound() : Node::fromRow($row);
    }

    /**
     * The node if the actor is its owner or Tech-C.
     *
     * @throws NotFound
     * @throws Denied
     */
    public function managed(Actor $actor, int $nodeId, bool $lock = false): Node
    {
        $node = $this->node($nodeId, $lock);

        return $node->isManagedBy($actor->effectiveId) ? $node : throw new Denied();
    }

    /**
     * The node if the actor is its owner.
     *
     * @throws NotFound
     * @throws Denied
     */
    public function owned(Actor $actor, int $nodeId, bool $lock = false): Node
    {
        $node = $this->node($nodeId, $lock);

        return $node->isOwnedBy($actor->effectiveId) ? $node : throw new Denied();
    }
}
