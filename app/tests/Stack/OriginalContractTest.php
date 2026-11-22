<?php

namespace App\Tests\Stack;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Behaviour of the original DB routines that the rewrite has to keep (see docs/teststack.md).
 * All calls go through DBAL with bound parameters, like the production code will.
 */
final class OriginalContractTest extends StackTestCase
{
    private const DEVICE_ARGS = [1, 'probe', null, null, null, null, true];

    public function testInsertDeviceTakesFirstFreeMainIpInTextOrder(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $id = (int) $db->fetchOne('SELECT insertdevice(?, ?, ?, ?, ?, ?, ?)', self::DEVICE_ARGS);

            // 10.12.0.4 is free too, but the varchar column sorts as text
            self::assertSame('10.12.0.10', $db->fetchOne('SELECT ip FROM ips WHERE id_devices = ?', [$id]));
        });
    }

    public function testInsertDeviceFailsWithoutFreeMainIp(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $db->executeStatement("UPDATE ips SET usage = 'spare' WHERE usage = 'main' AND id_devices IS NULL");
            $this->expectException(DriverException::class);
            $this->expectExceptionMessage('no free IPs');

            $db->fetchOne('SELECT insertdevice(?, ?, ?, ?, ?, ?, ?)', self::DEVICE_ARGS);
        });
    }

    public function testNodeNamesAreUniqueIgnoringCase(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $this->expectException(UniqueConstraintViolationException::class);
            $this->expectExceptionMessage('nodes_unique_ci');

            $db->fetchOne('SELECT insertnode(?, ?, ?, ?, ?, ?, ?, ?, ?)', [3, 'ALPHA', null, null, null, null, null, null, true]);
        });
    }

    public function testInsertNodeMakesOwnerTechContactToo(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $id = (int) $db->fetchOne('SELECT insertnode(?, ?, ?, ?, ?, ?, ?, ?, ?)', [6, 'probe', 48, 12, 30.5, 16, 21, 0, true]);

            self::assertGreaterThan(4, $id);
            self::assertSame([6, 6], array_map('intval', array_values($db->fetchAssociative('SELECT id_members, id_tech_c FROM nodes WHERE id = ?', [$id]))));
        });
    }

    public function testMacAddressesAreUnique(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $this->expectException(UniqueConstraintViolationException::class);
            $this->expectExceptionMessage('devices_unique2');

            $db->fetchOne('SELECT insertdevice(?, ?, ?, ?, ?, ?, ?)', [1, 'probe', null, null, null, '02:00:00:00:00:01', true]);
        });
    }

    public function testInsertIp6NormalisesAndRejectsDuplicates(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $id = (int) $db->fetchOne(
                'SELECT insertip6(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['n', 3, '2A02:0061:0003:0000:0000:0000:0000:0001', 'gamma-6', true, true, true, null, null, null, null],
            );
            self::assertSame('2a02:61:3::1', $db->fetchOne('SELECT ip6 FROM ip6 WHERE id = ?', [$id]));

            $this->expectException(UniqueConstraintViolationException::class);
            $this->expectExceptionMessage('ip6_unique6');
            $db->fetchOne(
                'SELECT insertip6(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['n', 3, '2a02:61:3:0:0:0:0:1', 'other', true, true, true, null, null, null, null],
            );
        });
    }

    public function testInsertV642TakesAFreeAddressAndCreatesTheIpv6Pair(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $id = (int) $db->fetchOne('SELECT insertv642(?, ?, ?, ?, ?, ?)', [2, 201, 'probe', true, true, true]);

            self::assertSame('185.194.20.2', $db->fetchOne('SELECT ip FROM ips WHERE id_devices = ?', [$id]));
            self::assertSame(
                ['2a02:61:0:ee:0:ffff:b9c2:1402' => 's-probe', '2a02:61:0:ee:1:ffff:b9c2:1402' => 'probe'],
                $db->fetchAllKeyValue('SELECT ip6, name FROM ip6 WHERE id_devices = ? ORDER BY ip6', [$id]),
            );
        });
    }

    public function testInsertV642RejectsAnAddressThatIsInUse(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $this->expectException(DriverException::class);
            $this->expectExceptionMessage('already in use');

            $db->fetchOne('SELECT insertv642(?, ?, ?, ?, ?, ?)', [2, 4, 'probe', true, true, true]);
        });
    }

    public function testUpdateV642WithANewIpv4RecreatesTheDevice(): void
    {
        $this->rollingBack(function (Connection $db): void {
            // DBAL binds an untyped PHP false as '', which PostgreSQL rejects as boolean: use 0 or ParameterType::BOOLEAN
            $db->executeStatement('SELECT updatev642(?, ?, ?, ?, ?, ?, ?)', [4, 202, 'alpha-v642', true, true, true, 0]);

            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM devices WHERE id = 4'));
            self::assertNull($db->fetchOne('SELECT id_devices FROM ips WHERE id = 4'));
            $new = $db->fetchAssociative("SELECT d.id, i.ip FROM devices d JOIN ips i ON i.id_devices = d.id WHERE d.name = 'alpha-v642'");
            self::assertGreaterThan(4, (int) $new['id']);
            self::assertSame('185.194.20.3', $new['ip']);
        });
    }

    public function testDeleteV642RemovesIpv6AndFreesTheIpv4(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $db->executeStatement('SELECT deletev642(?)', [4]);

            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM ip6 WHERE id IN (4, 5)'));
            self::assertNull($db->fetchOne('SELECT id_devices FROM ips WHERE id = 4'));
        });
    }

    public function testDeleteMemberRemovesRolesButNotNodeOwners(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $db->executeStatement('SELECT deletemember(?)', [9]);

            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM members_roles WHERE id_members = 9'));
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM members WHERE id = 9'));
        });
    }

    public function testNodeWithDevicesCannotBeDeleted(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $this->expectExceptionMessage('devices_fkey1');

            $db->executeStatement('SELECT deletenode(?)', [1]);
        });
    }

    public function testProposalsAreNodeRowsWithNegatedId(): void
    {
        $proposals = $this->db()->fetchAllAssociative('SELECT id, name, id_members, id_tech_c FROM nodes WHERE id < 0 ORDER BY id');

        self::assertSame(
            [
                ['id' => -2, 'name' => '-2', 'id_members' => 7, 'id_tech_c' => 4],
                ['id' => -1, 'name' => '-1', 'id_members' => 3, 'id_tech_c' => 6],
            ],
            array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'id_members' => (int) $row['id_members'], 'id_tech_c' => (int) $row['id_tech_c']], $proposals),
        );
    }

    public function testMailboxCheckCacheIsKeyedByMd5OfTheAddress(): void
    {
        $row = $this->db()->fetchAssociative('SELECT code, ca FROM verify WHERE hash = ?', [md5('testuser@example.test')]);

        self::assertSame([250, 550], [(int) $row['code'], (int) $row['ca']]);
    }

    public function testLastseenIgnoresTheV642SourceAddress(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $db->executeStatement("SELECT update_lastseen('2a02:61:0:ee:0:ffff:b9c2:1401', '2026-02-03 04:05:06')");

            self::assertNull($db->fetchOne('SELECT last_seen FROM devices WHERE id = 4'));
        });
    }
}
