<?php

namespace App\Node;

/**
 * A pending handover of a node. The legacy schema stores it as a row of the table nodes with the negated node
 * id (and that id as name), so a node has at most one:
 * - id_members differs from the node's owner: id_members shall become the owner; id_tech_c is the Tech-C
 *   afterwards, either the new owner or the current owner ("stay Tech-C").
 * - id_members is the node's owner: id_tech_c is invited as Tech-C.
 */
final readonly class Proposal
{
    private function __construct(
        public Node $node,
        public ProposalKind $kind,
        public int $recipientId,
        /** Tech-C once the proposal is accepted */
        public int $techCId,
    ) {
    }

    /**
     * @param array{id_members: mixed, id_tech_c: mixed} $row the proposal row of $node
     *
     * @return self|null null if the row does not describe a handover of the node as it is now
     */
    public static function fromRow(Node $node, array $row): ?self
    {
        $owner = (int) $row['id_members'];
        $techC = (int) $row['id_tech_c'];
        if ($owner !== $node->ownerId) {
            return in_array($techC, [$owner, $node->ownerId], true) ? new self($node, ProposalKind::Owner, $owner, $techC) : null;
        }

        return $techC !== $node->ownerId ? new self($node, ProposalKind::TechC, $techC, $techC) : null;
    }

    public function transfersOwnership(): bool
    {
        return ProposalKind::Owner === $this->kind;
    }

    public function ownerStaysTechC(): bool
    {
        return $this->transfersOwnership() && $this->techCId !== $this->recipientId;
    }

    /** Owner after acceptance. */
    public function ownerId(): int
    {
        return $this->transfersOwnership() ? $this->recipientId : $this->node->ownerId;
    }

    /** What the deciding member saw. A withdrawn or replaced proposal has no or another version. */
    public function version(): string
    {
        return sprintf('%s-%d-%d', $this->kind->value, $this->recipientId, $this->techCId);
    }
}
