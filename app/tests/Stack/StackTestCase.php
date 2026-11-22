<?php

namespace App\Tests\Stack;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Ldap\Ldap;

/** Tests resolve DBAL and LDAP from the production container: same factories, same configuration. */
abstract class StackTestCase extends KernelTestCase
{
    protected function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    protected function ldap(): Ldap
    {
        return self::getContainer()->get(Ldap::class);
    }

    protected function bindAsService(): Ldap
    {
        $ldap = $this->ldap();
        $ldap->bind((string) getenv('LDAP_SERVICE_DN'), (string) getenv('LDAP_SERVICE_PASSWORD'));

        return $ldap;
    }

    protected function baseDn(): string
    {
        return (string) getenv('LDAP_BASE_DN');
    }

    /** Runs the test body in a transaction that is always rolled back, so the seed stays untouched. */
    protected function rollingBack(callable $body): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $body($db);
        } finally {
            $db->rollBack();
        }
    }
}
