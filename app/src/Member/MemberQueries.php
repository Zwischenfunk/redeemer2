<?php

namespace App\Member;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class MemberQueries
{
    public function __construct(private Connection $db)
    {
    }

    public function profile(int $id): ?Profile
    {
        $row = $this->db->fetchAssociative(
            'SELECT nickname, firstname, lastname, street, housenumber, zip, town, telephone, mobilephone, fax, email, homepage FROM members WHERE id = ?',
            [$id],
        );

        return false === $row ? null : Profile::fromArray($row);
    }

    /** @return list<int> */
    public function roleIds(int $id): array
    {
        return array_map(intval(...), $this->db->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = ? ORDER BY id_roles', [$id]));
    }

    /** @return array<int, string> */
    public function roleNames(): array
    {
        return array_map('strval', array_column($this->db->fetchAllAssociative('SELECT id, name FROM roles ORDER BY id'), 'name', 'id'));
    }

    public function nicknameTaken(string $nickname): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM members WHERE lower(nickname) = lower(?)', [$nickname]);
    }

    /** @return list<string> reasons why the member must not be deleted */
    public function deleteBlockers(int $id): array
    {
        $blockers = [];
        if ($this->db->fetchOne('SELECT 1 FROM members_roles WHERE id_members = ? AND id_roles BETWEEN 1 AND 8', [$id])) {
            $blockers[] = 'Fehler - dieser User hat noch einen Mitgliedsstatus! Bitte zuerst Vereinszugehörigkeit auflösen';
        }
        if ($this->db->fetchOne('SELECT 1 FROM nodes WHERE id_members = ? OR id_tech_c = ?', [$id, $id])) {
            $blockers[] = 'Fehler - dieser User hat noch Nodes als Besitzer bzw. Tech-C zugeordnet';
        }

        return $blockers;
    }

    /** @return list<array{id: int, nickname: string, email: string}> members matching the nickname or the e-mail address */
    public function loginMatches(string $nickname, string $email): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, nickname, email FROM members WHERE id > 0 AND (nickname = lower(?) OR (? <> \'\' AND lower(email) = lower(?)))',
            [$nickname, $email, $email],
        );

        return array_map(static fn (array $row) => ['id' => (int) $row['id'], 'nickname' => (string) $row['nickname'], 'email' => (string) $row['email']], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function search(SearchFilter $filter, bool $withStreet): array
    {
        $where = ['true'];
        $params = [];
        $types = [];
        if ('' !== $filter->term) {
            $haystack = "'#'||m.nickname||'#'||coalesce(m.firstname,'')||' '||coalesce(m.lastname,'')||' '||coalesce(m.firstname,'')||'#'||"
                .($withStreet ? "coalesce(m.street,'')||'#'||" : '')
                ."coalesce(m.email,'')||'#'||coalesce(m.telephone,'')||'#'||coalesce(m.mobilephone,'')||'#'";
            $clause = 'lower('.$haystack.') LIKE lower(?)';
            $params[] = '%'.$filter->term.'%';
            $types[] = ParameterType::STRING;
            if (ctype_digit($filter->term) && strlen($filter->term) < 10) {
                $clause .= ' OR m.id = ?';
                $params[] = (int) $filter->term;
                $types[] = ParameterType::INTEGER;
            }
            $where[] = '('.$clause.')';
        }
        if ([] !== $filter->inGroups) {
            $where[] = 'm.id IN (SELECT id_members FROM members_roles WHERE id_roles IN (?))';
            $params[] = $filter->inGroups;
            $types[] = ArrayParameterType::INTEGER;
        }
        if ([] !== $filter->offGroups) {
            $where[] = 'm.id NOT IN (SELECT id_members FROM members_roles WHERE id_roles IN (?))';
            $params[] = $filter->offGroups;
            $types[] = ArrayParameterType::INTEGER;
        }
        $params[] = $filter->limited ? 30 : 150;
        $types[] = ParameterType::INTEGER;

        $sql = <<<'SQL'
            SELECT m.id, m.nickname, m.firstname, m.lastname, m.email,
                   (SELECT min(r.id_roles) FROM members_roles r WHERE r.id_members = m.id AND r.id_roles BETWEEN 3 AND 6) AS state,
                   (SELECT min(r.id_roles) FROM members_roles r WHERE r.id_members = m.id AND r.id_roles BETWEEN 1 AND 2) AS admin,
                   EXISTS (SELECT 1 FROM members_roles r WHERE r.id_members = m.id AND r.id_roles = 9) AS deleteme,
                   (SELECT count(*) FROM nodes n WHERE n.id_members = m.id AND n.id > 0) AS nodes,
                   (SELECT count(*) FROM nodes n WHERE n.id_members != m.id AND n.id_tech_c = m.id AND n.id > 0) AS techc,
                   m.changed, m.last_login, m.created
            FROM members m
            WHERE
            SQL;
        $sql .= ' '.implode(' AND ', $where).' ORDER BY m.id LIMIT ?';

        return $this->db->fetchAllAssociative($sql, $params, $types);
    }
}
