<?php

namespace App\Node;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** What the node overview and the home page show. Proposal rows (negative ids) only appear as proposals. */
final readonly class NodeQueries
{
    /** "online" as on the home page: seen within four hours, midnight placeholders excluded. */
    private const LISTED = <<<'SQL'
        SELECT n.*, p.id_members AS proposal_owner, p.id_tech_c AS proposal_tech_c,
               ROW(p.xmin, p.ctid, p.created)::text AS proposal_row_version,
               (SELECT count(*) FROM devices d WHERE d.id_nodes = n.id) AS v4,
               (SELECT count(*) FROM ip6 i LEFT JOIN devices d ON d.id = i.id_devices WHERE n.id IN (i.id_nodes, d.id_nodes)) AS v6,
               coalesce(n.last_seen >= now() - interval '4 hours' AND to_char(n.last_seen, 'HH24MISS') <> '000000', false) AS online
          FROM nodes n LEFT JOIN nodes p ON p.id = -n.id
         WHERE n.id > 0 AND %s
         ORDER BY lower(n.name)
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{node: Node, proposal: Proposal|null, v4: int, v6: int, online: bool}> the member's own nodes */
    public function owned(int $memberId): array
    {
        return $this->listed('n.id_members = :member', $memberId);
    }

    /** @return list<array{node: Node, proposal: Proposal|null, v4: int, v6: int, online: bool}> other owners' nodes the member is Tech-C of */
    public function techContactOf(int $memberId): array
    {
        return $this->listed('n.id_tech_c = :member AND n.id_members <> :member', $memberId);
    }

    /** @return list<Proposal> pending proposals addressed to the member */
    public function incoming(int $memberId): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT n.*, p.id_members AS proposal_owner, p.id_tech_c AS proposal_tech_c,
                   ROW(p.xmin, p.ctid, p.created)::text AS proposal_row_version
              FROM nodes p JOIN nodes n ON n.id = -p.id
             WHERE p.id < 0 AND :member IN (p.id_members, p.id_tech_c)
             ORDER BY lower(n.name)
            SQL, ['member' => $memberId]);
        $proposals = array_map($this->proposal(...), $rows);

        return array_values(array_filter($proposals, static fn (?Proposal $proposal) => $proposal?->recipientId === $memberId));
    }

    /**
     * @param list<int> $memberIds
     *
     * @return array<int, array{firstname: string, lastname: string, email: string}> by member id
     */
    public function contacts(array $memberIds): array
    {
        $contacts = [];
        $rows = $this->db->fetchAllAssociative('SELECT id, firstname, lastname, email FROM members WHERE id IN (?)', [$memberIds], [ArrayParameterType::INTEGER]);
        foreach ($rows as $row) {
            $contacts[(int) $row['id']] = ['firstname' => (string) $row['firstname'], 'lastname' => (string) $row['lastname'], 'email' => (string) $row['email']];
        }

        return $contacts;
    }

    /** @return list<array{node: Node, proposal: Proposal|null, v4: int, v6: int, online: bool}> */
    private function listed(string $condition, int $memberId): array
    {
        $rows = $this->db->fetchAllAssociative(sprintf(self::LISTED, $condition), ['member' => $memberId]);

        return array_map(fn (array $row) => [
            'node' => Node::fromRow($row),
            'proposal' => $this->proposal($row),
            'v4' => (int) $row['v4'],
            'v6' => (int) $row['v6'],
            'online' => (bool) $row['online'],
        ], $rows);
    }

    /** @param array<string, mixed> $row a node row joined with its proposal row */
    private function proposal(array $row): ?Proposal
    {
        return null === $row['proposal_owner'] ? null : Proposal::fromRow(Node::fromRow($row), [
            'id_members' => $row['proposal_owner'],
            'id_tech_c' => $row['proposal_tech_c'],
            'row_version' => $row['proposal_row_version'],
        ]);
    }
}
