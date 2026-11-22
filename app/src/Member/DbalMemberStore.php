<?php

namespace App\Member;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\ParameterType;

final readonly class DbalMemberStore implements MemberStore
{
    public function __construct(private Connection $db)
    {
    }

    public function insert(string $nickname, Profile $profile): int
    {
        try {
            $id = $this->db->fetchOne(
                'SELECT insertmember(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$nickname, $profile->firstname, $profile->lastname, $profile->street, $profile->housenumber, $profile->zip, $profile->town, $profile->telephone, $profile->mobilephone, $profile->fax, $profile->email, $profile->homepage],
            );
        } catch (DatabaseException $exception) {
            throw new BackendFailure('Der Datenbank-Eintrag konnte nicht angelegt werden.', previous: $exception);
        }

        return (int) $id;
    }

    public function update(int $memberId, Profile $profile, array $roleChanges): void
    {
        try {
            $this->db->transactional(function () use ($memberId, $profile, $roleChanges): void {
                $this->db->fetchOne(
                    'SELECT updatemember(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$memberId, $profile->nickname, $profile->firstname, $profile->lastname, $profile->street, $profile->housenumber, $profile->zip, $profile->town, $profile->telephone, $profile->mobilephone, $profile->fax, $profile->email, $profile->homepage],
                );
                foreach ($roleChanges as ['ids' => $ids, 'selected' => $selected]) {
                    $this->db->executeStatement('DELETE FROM members_roles WHERE id_members = ? AND id_roles IN (?)', [$memberId, $ids], [ParameterType::INTEGER, ArrayParameterType::INTEGER]);
                    if ($selected > 0) {
                        $this->db->executeStatement('INSERT INTO members_roles (id_roles, id_members) VALUES (?, ?)', [$selected, $memberId]);
                    }
                }
            });
        } catch (DatabaseException $exception) {
            throw new BackendFailure('Die Datenbank konnte nicht aktualisiert werden.', previous: $exception);
        }
    }

    public function delete(int $memberId): void
    {
        try {
            $this->db->executeStatement('SELECT deletemember(?)', [$memberId]);
        } catch (DatabaseException $exception) {
            throw new BackendFailure('Der Datenbank-Eintrag konnte nicht gelöscht werden.', previous: $exception);
        }
    }
}
