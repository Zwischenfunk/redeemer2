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
        private string $rowVersion,
    ) {
    }

    /**
     * @param array{id_members: mixed, id_tech_c: mixed, row_version: mixed} $row the proposal row and its PostgreSQL tuple identity
     *
     * @return self|null null if the row does not describe a handover of the node as it is now
     */
    public static function fromRow(Node $node, array $row): ?self
    {
        $owner = (int) $row['id_members'];
        $techC = (int) $row['id_tech_c'];
        $rowVersion = (string) $row['row_version'];
        if ($owner !== $node->ownerId) {
            return in_array($techC, [$owner, $node->ownerId], true) ? new self($node, ProposalKind::Owner, $owner, $techC, $rowVersion) : null;
        }

        return $techC !== $node->ownerId ? new self($node, ProposalKind::TechC, $techC, $techC, $rowVersion) : null;
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

    /** Binds the confirmation to the node, terms, and this issuance without changing the legacy row format. */
    public function version(): string
    {
        return hash('sha256', sprintf('%d:%d:%s:%d:%d:%s', $this->node->id, $this->node->ownerId, $this->kind->value, $this->recipientId, $this->techCId, $this->rowVersion));
    }
}
