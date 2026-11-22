<?php

namespace App\Tests\Http;

use App\Tests\Stack\StackTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ExceptionInterface;
use Symfony\Component\Ldap\Adapter\ExtLdap\EntryManager;
use Symfony\Component\Ldap\Adapter\ExtLdap\UpdateOperation;

/**
 * Tests that mutate members: the HTTP stack, PostgreSQL and LDAP are shared, so every test restores
 * members, members_roles, the id sequence and all LDAP entries to the state it started from.
 */
abstract class HttpStackTestCase extends StackTestCase
{
    protected const PASSWORD = 'rewrite-test-only';

    /** @var list<array<string, mixed>> */
    private array $members = [];
    /** @var list<array<string, mixed>> */
    private array $memberRoles = [];
    /** @var array<string, mixed> */
    private array $sequence = [];
    /** @var array<string, array<string, list<string>>> */
    private array $directory = [];

    protected function setUp(): void
    {
        $db = $this->db();
        $this->members = $db->fetchAllAssociative('SELECT * FROM members ORDER BY id');
        $this->memberRoles = $db->fetchAllAssociative('SELECT * FROM members_roles ORDER BY id_roles, id_members');
        $this->sequence = $db->fetchAssociative('SELECT last_value, is_called FROM members_id_seq');
        foreach ($this->bindAsService()->query($this->baseDn(), '(objectClass=posixAccount)')->execute() as $entry) {
            $this->directory[$entry->getDn()] = $entry->getAttributes();
        }
        $this->mailpit('DELETE', '/api/v1/messages');
    }

    protected function tearDown(): void
    {
        $this->restoreDatabase();
        $this->restoreDirectory();
    }

    private function restoreDatabase(): void
    {
        $db = $this->db();
        if ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $db->beginTransaction();
        try {
            // The "changed" trigger would otherwise stamp the restored rows.
            $db->executeStatement('ALTER TABLE members DISABLE TRIGGER USER');
            $db->executeStatement('DELETE FROM members_roles');
            $db->executeStatement('DELETE FROM members WHERE id NOT IN ('.implode(',', array_map(intval(...), array_column($this->members, 'id'))).')');
            $current = [];
            foreach ($db->fetchAllAssociative('SELECT * FROM members') as $row) {
                $current[$row['id']] = $row;
            }
            foreach ($this->members as $row) {
                if (($current[$row['id']] ?? null) === $row) {
                    continue;
                }
                $columns = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $sets = implode(',', array_map(static fn (string $c) => $c.' = EXCLUDED.'.$c, array_filter($columns, static fn (string $c) => 'id' !== $c)));
                $db->executeStatement(sprintf('INSERT INTO members (%s) VALUES (%s) ON CONFLICT (id) DO UPDATE SET %s', implode(',', $columns), $placeholders, $sets), array_values($row));
            }
            foreach ($this->memberRoles as $row) {
                $db->executeStatement('INSERT INTO members_roles (id_roles, id_members) VALUES (?, ?)', [$row['id_roles'], $row['id_members']]);
            }
            $db->executeStatement('ALTER TABLE members ENABLE TRIGGER USER');
            $db->executeQuery('SELECT setval(\'members_id_seq\', ?, ?)', [$this->sequence['last_value'], $this->sequence['is_called']], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function restoreDirectory(): void
    {
        $ldap = $this->bindAsService();
        $manager = $ldap->getEntryManager();
        $current = [];
        foreach ($ldap->query($this->baseDn(), '(objectClass=posixAccount)')->execute() as $entry) {
            $current[$entry->getDn()] = $entry->getAttributes();
        }
        foreach ($current as $dn => $attributes) {
            if (!isset($this->directory[$dn])) {
                $manager->remove(new Entry($dn));
            }
        }
        foreach ($this->directory as $dn => $attributes) {
            if (!isset($current[$dn])) {
                $manager->add(new Entry($dn, $attributes));
                continue;
            }
            $operations = [];
            foreach ($attributes as $name => $values) {
                if (($current[$dn][$name] ?? null) !== $values) {
                    $operations[] = new UpdateOperation(LDAP_MODIFY_BATCH_REPLACE, $name, $values);
                }
            }
            if ([] !== $operations) {
                self::assertInstanceOf(EntryManager::class, $manager);
                $manager->applyOperations($dn, $operations);
            }
        }
    }

    /** @return array<string, mixed> */
    protected function mailpit(string $method, string $path): array
    {
        $response = HttpClient::createForBaseUri((string) getenv('MAILPIT_URL'))->request($method, $path);

        return 'GET' === $method ? $response->toArray() : [];
    }

    /** @return list<array<string, mixed>> */
    protected function messages(): array
    {
        return $this->mailpit('GET', '/api/v1/messages')['messages'];
    }

    protected function messageText(string $id): string
    {
        return (string) $this->mailpit('GET', '/api/v1/message/'.$id)['Text'];
    }

    /** @return array<string, list<string>>|null */
    protected function ldapEntry(string $uid): ?array
    {
        $found = $this->bindAsService()->query($this->baseDn(), '(&(objectClass=posixAccount)(uid='.$this->ldap()->escape($uid, '', LDAP_ESCAPE_FILTER).'))')->execute()->toArray();

        return [] === $found ? null : $found[0]->getAttributes();
    }

    protected function canBindAs(string $uid, string $password): bool
    {
        try {
            $this->ldap()->bind('uid='.$this->ldap()->escape($uid, '', LDAP_ESCAPE_DN).','.$this->baseDn(), $password);

            return true;
        } catch (ExceptionInterface) {
            return false;
        }
    }

    protected function loggedIn(string $nickname): Browser
    {
        return $this->loggedInWith($nickname, self::PASSWORD);
    }

    protected function loggedInWith(string $nickname, string $password): Browser
    {
        $browser = new Browser();
        $login = $browser->login($nickname, $password);
        self::assertSame(301, $login->getStatusCode(), 'Login of '.$nickname.' failed.');

        return $browser;
    }

    /** @return array<string, string> a valid create form */
    protected function newMember(string $nickname = 'newbie'): array
    {
        return [
            'nickname' => $nickname, 'firstname' => 'Nina', 'lastname' => 'Neu', 'street' => 'Neugasse', 'housenumber' => '12a',
            'zip' => '1010', 'town' => 'Wien', 'telephone' => '', 'mobilephone' => '+43 660 1234567', 'fax' => '',
            'email' => $nickname.'@example.test', 'homepage' => 'https://example.test/',
        ];
    }

    /** @param array<string, mixed> $values */
    protected function createMember(Browser $browser, array $values): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $token = $browser->tokenFrom('/?section=member&action=insert', 'member-create');

        return $browser->post('/?section=member&action=insert', $values + ['_csrf_token' => $token]);
    }
}
