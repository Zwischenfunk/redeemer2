<?php

namespace App\Tests\Stack;

use Doctrine\DBAL\Connection;

final class DatabaseTest extends StackTestCase
{
    public function testServerIsPostgres13(): void
    {
        self::assertStringStartsWith('PostgreSQL 13.', (string) $this->db()->fetchOne('SELECT version()'));
    }

    public function testAdminAndSuperuserRolesHaveFixedIds(): void
    {
        $roles = $this->db()->fetchAllKeyValue('SELECT id, name FROM roles WHERE id IN (1, 2)');

        self::assertSame([1 => 'Admin', 2 => 'Superuser'], $roles);
    }

    public function testOriginalRoleIdsAreSeeded(): void
    {
        $ids = array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM roles ORDER BY id'));

        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7, 9, 32], $ids);
    }

    public function testRoleHoldersAreSeeded(): void
    {
        $holders = $this->db()->fetchAllKeyValue('SELECT id_roles, count(*) FROM members_roles WHERE id_roles IN (1, 2) GROUP BY id_roles');

        self::assertSame([1 => 1, 2 => 1], array_map('intval', $holders));
    }

    public function testSeveralOwnersAndTechContacts(): void
    {
        self::assertGreaterThanOrEqual(3, (int) $this->db()->fetchOne('SELECT count(DISTINCT id_members) FROM nodes WHERE id > 0'));
        self::assertGreaterThanOrEqual(3, (int) $this->db()->fetchOne('SELECT count(DISTINCT id_tech_c) FROM nodes WHERE id > 0'));
        self::assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT count(*) FROM nodes WHERE id > 0 AND id_members <> id_tech_c'));
    }

    public function testFreePoolsExistForMainAndV642(): void
    {
        $free = $this->db()->fetchAllKeyValue("SELECT usage, count(*) FROM ips WHERE id_devices IS NULL AND usage IN ('main', 'v642') GROUP BY usage");

        self::assertSame(['main' => 20, 'v642' => 20], array_map('intval', $free));
    }

    public function testInsertMemberReturnsIdAndMemberLoginStampsLastLogin(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $id = (int) $db->fetchOne(
                'SELECT insertmember(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['probe', 'Pia', 'Probe', 'Weg', '1', '1010', 'Wien', null, null, null, 'probe@example.test', null],
            );
            self::assertGreaterThan(9, $id);
            self::assertNull($db->fetchOne('SELECT last_login FROM members WHERE id = ?', [$id]));

            $db->executeStatement('SELECT member_login(?)', ['PROBE']);

            self::assertNotNull($db->fetchOne('SELECT last_login FROM members WHERE id = ?', [$id]));
        });
    }

    public function testUpdateLastseenPropagatesToDeviceAndNode(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $db->executeStatement("SELECT update_lastseen('10.12.0.3', '2026-02-03 04:05:06')");

            self::assertStringStartsWith('2026-02-03 04:05:06', (string) $db->fetchOne('SELECT last_seen FROM devices WHERE id = 3'));
            self::assertStringStartsWith('2026-02-03 04:05:06', (string) $db->fetchOne('SELECT last_seen FROM nodes WHERE id = 3'));
        });
    }
}
