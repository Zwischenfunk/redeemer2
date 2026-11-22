<?php

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;

final readonly class MemberDashboard
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, mixed> */
    public function read(int $memberId): array
    {
        $labels = [
            3 => 'ordentliches Mitglied', 4 => 'außerordentliches Mitglied', 5 => 'kein Mitglied',
            6 => 'förderndes Mitglied', 7 => 'Ehrenmitglied',
        ];
        $roles = $this->db->fetchFirstColumn(
            'SELECT id_roles FROM members_roles WHERE id_members = ? AND id_roles BETWEEN 3 AND 7 ORDER BY id_roles DESC',
            [$memberId],
        );
        $statuses = array_map(static fn ($role) => $labels[(int) $role], $roles);
        $counts = false;
        // The legacy home tests the first label alphabetically, not the lowest numeric role.
        if ($statuses && in_array(min($statuses), [$labels[3], $labels[4], $labels[7]], true)) {
            $counts = $this->db->fetchAssociative(<<<'SQL'
                SELECT count(*) AS total,
                       count(*) FILTER (WHERE role = 3) AS ordinary,
                       count(*) FILTER (WHERE role = 4) AS extraordinary,
                       count(*) FILTER (WHERE role = 7) AS honorary
                  FROM (
                      SELECT coalesce(max(id_roles) FILTER (WHERE id_roles = 7), min(id_roles)) AS role
                        FROM members_roles WHERE id_roles IN (3, 4, 7) GROUP BY id_members
                  ) ranked
                SQL);
        }

        $proposals = $this->db->fetchOne(<<<'SQL'
            SELECT count(*) FROM nodes proposal
             WHERE proposal.id < 0 AND :member IN (proposal.id_members, proposal.id_tech_c)
               AND proposal.id_members <> proposal.id_tech_c
               AND NOT EXISTS (
                   SELECT FROM nodes owned WHERE owned.id = -proposal.id AND owned.id_members = :member
               )
            SQL, ['member' => $memberId]);

        $stats = $this->db->fetchAllAssociative(<<<'SQL'
            WITH relevant AS (
                SELECT id, last_seen, CASE WHEN id_members = :member THEN 'Eigene Nodes' ELSE 'Tech-C' END AS node_group
                  FROM nodes WHERE id > 0 AND id_members >= 0 AND id_tech_c >= 0 AND :member IN (id_members, id_tech_c)
            ), observations AS (
                SELECT id AS node_id, node_group, 'node' AS kind, last_seen FROM relevant
                UNION ALL
                SELECT n.id, n.node_group, 'v4', d.last_seen FROM relevant n JOIN devices d ON d.id_nodes = n.id
                UNION ALL
                SELECT n.id, n.node_group, 'v6', i.last_seen FROM relevant n JOIN ip6 i ON i.id_nodes = n.id
                UNION ALL
                SELECT n.id, n.node_group, 'v6', i.last_seen
                  FROM relevant n JOIN devices d ON d.id_nodes = n.id JOIN ip6 i ON i.id_devices = d.id
            ), online AS (
                SELECT *, last_seen >= now() - interval '4 hours' AND to_char(last_seen, 'HH24MISS') <> '000000' AS seen
                  FROM observations
            )
            SELECT coalesce(node_group, 'Summe') AS label,
                   count(*) FILTER (WHERE kind = 'node') AS nodes,
                   count(DISTINCT node_id) FILTER (WHERE kind <> 'node') AS nodes_with_ip,
                   count(*) FILTER (WHERE kind = 'node' AND seen) AS nodes_online,
                   count(*) FILTER (WHERE kind = 'v4') AS v4,
                   count(*) FILTER (WHERE kind = 'v4' AND seen) AS v4_online,
                   count(*) FILTER (WHERE kind = 'v6') AS v6,
                   count(*) FILTER (WHERE kind = 'v6' AND seen) AS v6_online
              FROM online GROUP BY GROUPING SETS ((node_group), ())
            HAVING node_group IS NOT NULL OR count(DISTINCT node_group) > 1
             ORDER BY node_group NULLS LAST
            SQL, ['member' => $memberId]);

        return ['statuses' => $statuses, 'counts' => $counts, 'proposals' => $proposals, 'stats' => $stats];
    }
}
