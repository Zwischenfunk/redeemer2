<?php

namespace App\Node;

/** A real node. Rows with a negative id are handover proposals (see Proposal) and never become a Node. */
final readonly class Node
{
    public const COORDINATES = ['gps_lat_deg', 'gps_lat_min', 'gps_lat_sec', 'gps_lon_deg', 'gps_lon_min', 'gps_lon_sec'];

    /** @param array<string, float|null> $coordinates keyed by COORDINATES */
    public function __construct(
        public int $id,
        public string $name,
        public array $coordinates,
        public bool $map,
        public int $ownerId,
        public int $techCId,
    ) {
    }

    /** @param array<string, mixed> $row a row of the table nodes */
    public static function fromRow(array $row): self
    {
        $coordinates = [];
        foreach (self::COORDINATES as $field) {
            $coordinates[$field] = null === $row[$field] ? null : (float) $row[$field];
        }

        return new self((int) $row['id'], (string) $row['name'], $coordinates, (bool) $row['map'], (int) $row['id_members'], (int) $row['id_tech_c']);
    }

    public function isOwnedBy(int $memberId): bool
    {
        return $this->ownerId === $memberId;
    }

    /** Owner and Tech-C manage the node, its devices and its addresses. */
    public function isManagedBy(int $memberId): bool
    {
        return $this->ownerId === $memberId || $this->techCId === $memberId;
    }

    public function hasSeparateTechC(): bool
    {
        return $this->techCId !== $this->ownerId;
    }

    public function latitude(): ?float
    {
        return $this->decimal('gps_lat');
    }

    public function longitude(): ?float
    {
        return $this->decimal('gps_lon');
    }

    /** The node id as it appears in the node's IPv6 user block, e.g. 0x001A. */
    public function hexId(): string
    {
        return sprintf('0x%04X', $this->id);
    }

    /** Decimal degrees; like the legacy list, a position needs degrees other than 0 plus minutes and seconds. */
    private function decimal(string $prefix): ?float
    {
        [$deg, $min, $sec] = [$this->coordinates[$prefix.'_deg'], $this->coordinates[$prefix.'_min'], $this->coordinates[$prefix.'_sec']];

        return null === $deg || 0.0 === $deg || null === $min || null === $sec ? null : $deg + $min / 60 + $sec / 3600;
    }
}
