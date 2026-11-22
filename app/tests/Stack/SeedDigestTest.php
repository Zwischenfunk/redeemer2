<?php

namespace App\Tests\Stack;

use Doctrine\DBAL\Connection;

/**
 * Two fresh starts must yield identical seeds. Run this before any test that changes data
 * (all mutating tests roll back), e.g. via `make rewrite-probe`. After deliberate seed changes,
 * copy the digests from the failure message.
 */
final class SeedDigestTest extends StackTestCase
{
    private const DB_DIGEST = 'a25b78d3c89d7ab2db210a99fad1744d487485a2442261afc852fc1ce3d96765';
    private const LDAP_DIGEST = 'fbd42bbc73593367d79a49f8bbeb0bc9579e502566c8539afc3c71af807eba69';

    public function testDatabaseSeedIsUnchanged(): void
    {
        $lines = [];
        $this->rollingBack(function (Connection $db) use (&$lines): void {
            $db->executeStatement("SET LOCAL TIME ZONE 'UTC'");
            foreach (['roles', 'members', 'members_roles', 'nodes', 'devices', 'ips', 'ip6', 'verify'] as $table) {
                $lines[] = '# '.$table;
                foreach ($db->fetchFirstColumn(sprintf('SELECT row_to_json(t)::text FROM %s t ORDER BY 1', $table)) as $row) {
                    $lines[] = $row;
                }
            }
        });

        self::assertSame(self::DB_DIGEST, hash('sha256', implode("\n", $lines)));
    }

    public function testLdapSeedIsUnchanged(): void
    {
        $ldap = $this->bindAsService();
        $dns = [];
        foreach ($ldap->query($this->baseDn(), '(objectClass=*)')->execute() as $entry) {
            $attributes = $entry->getAttributes();
            ksort($attributes);
            foreach ($attributes as &$values) {
                sort($values);
            }
            $dns[mb_strtolower($entry->getDn())] = json_encode($attributes, JSON_THROW_ON_ERROR);
        }
        ksort($dns);
        $lines = [];
        foreach ($dns as $dn => $json) {
            $lines[] = $dn.' '.$json;
        }

        self::assertSame(self::LDAP_DIGEST, hash('sha256', implode("\n", $lines)));
    }
}
