<?php

namespace App\Tests\Stack;

/**
 * Two fresh starts must yield identical seeds. Run this before any test that changes data
 * (all mutating tests roll back), e.g. via `make rewrite-probe`. After deliberate seed changes,
 * copy the digests from the failure message.
 */
final class SeedDigestTest extends StackTestCase
{
    private const DB_DIGEST = '63f7cf7ed8245456aa1c0dbaa6a322760ff1cebb1173dc2bdd0d7026464ae439';
    private const LDAP_DIGEST = 'e0ee26d80805a5e6d7bbc5e7345d6ac2dfb7ca2b2c27cae5311f59b84c1006d3';

    public function testDatabaseSeedIsUnchanged(): void
    {
        $db = $this->db();
        $lines = [];
        foreach (['roles', 'members', 'members_roles', 'nodes', 'devices', 'ips', 'ip6', 'verify'] as $table) {
            $lines[] = '# '.$table;
            $db->beginTransaction();
            try {
                $db->executeStatement("SET LOCAL TIME ZONE 'UTC'");
                foreach ($db->fetchFirstColumn(sprintf('SELECT row_to_json(t)::text FROM %s t ORDER BY 1', $table)) as $row) {
                    $lines[] = $row;
                }
            } finally {
                $db->rollBack();
            }
        }

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
