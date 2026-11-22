<?php

namespace App\Member;

use Symfony\Component\Ldap\Adapter\ExtLdap\EntryManager;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ExceptionInterface;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\Adapter\ExtLdap\UpdateOperation;

final class LdapMemberDirectory implements MemberDirectory
{
    public function __construct(
        private readonly Ldap $ldap,
        private readonly string $baseDn,
        private readonly string $serviceDn,
        #[\SensitiveParameter] private readonly string $servicePassword,
    ) {
    }

    public function nicknameExists(string $nickname): bool
    {
        return $this->guard(fn () => [] !== $this->find('uid', $nickname));
    }

    public function exists(int $memberId): bool
    {
        return $this->guard(fn () => [] !== $this->find('uidNumber', (string) $memberId));
    }

    public function add(string $nickname, Profile $profile, #[\SensitiveParameter] string $password): void
    {
        $this->guard(function () use ($nickname, $profile, $password): void {
            $this->bind();
            $this->ldap->getEntryManager()->add(new Entry($this->dn($nickname), [
                'objectClass' => ['top', 'posixAccount', 'inetOrgPerson', 'organizationalPerson', 'person'],
                'uid' => [$nickname],
                'uidNumber' => ['-1'],
                'gidNumber' => ['-1'],
                'homeDirectory' => ['/home/'.$nickname],
                'cn' => [$profile->firstname.' '.$profile->lastname],
                'sn' => [$profile->lastname],
                'givenName' => [$profile->firstname],
                'mail' => [$profile->email],
                'userPassword' => [$password],
            ]));
        }, 'Das LDAP-Konto konnte nicht angelegt werden.');
    }

    public function assignId(string $nickname, int $memberId): void
    {
        $this->guard(function () use ($nickname, $memberId): void {
            $this->bind();
            $this->modify($this->dn($nickname), [
                new UpdateOperation(LDAP_MODIFY_BATCH_REPLACE, 'uidNumber', [(string) $memberId]),
                new UpdateOperation(LDAP_MODIFY_BATCH_REPLACE, 'gidNumber', [(string) $memberId]),
            ]);
        }, 'LDAP-ID konnte nicht zugeordnet werden.');
    }

    public function update(int $memberId, #[\SensitiveParameter] array $attributes): void
    {
        $this->guard(function () use ($memberId, $attributes): void {
            $this->bind();
            $entry = $this->find('uidNumber', (string) $memberId);
            if (1 !== count($entry)) {
                throw new BackendFailure('Das LDAP-Konto wurde nicht eindeutig gefunden.');
            }
            $operations = array_map(
                static fn (string $name, string $value) => new UpdateOperation(LDAP_MODIFY_BATCH_REPLACE, $name, [$value]),
                array_keys($attributes),
                array_values($attributes),
            );
            $this->modify($entry[0]->getDn(), $operations);
        }, 'Das LDAP-Konto konnte nicht geändert werden.');
    }

    public function remove(int $memberId): void
    {
        $this->guard(function () use ($memberId): void {
            $this->bind();
            $entry = $this->find('uidNumber', (string) $memberId);
            if (1 !== count($entry)) {
                throw new BackendFailure('Das LDAP-Konto wurde nicht eindeutig gefunden.');
            }
            $this->ldap->getEntryManager()->remove($entry[0]);
        }, 'Das LDAP-Konto konnte nicht gelöscht werden.');
    }

    /** @return list<Entry> */
    private function find(string $attribute, string $value): array
    {
        $this->bind();
        $filter = '(&(objectClass=posixAccount)('.$attribute.'='.$this->ldap->escape($value, '', LDAP_ESCAPE_FILTER).'))';

        return $this->ldap->query($this->baseDn, $filter, ['maxItems' => 2])->execute()->toArray();
    }

    private function bind(): void
    {
        $this->ldap->bind($this->serviceDn, $this->servicePassword);
    }

    private function dn(string $nickname): string
    {
        return 'uid='.$this->ldap->escape($nickname, '', LDAP_ESCAPE_DN).','.$this->baseDn;
    }

    /** @param list<UpdateOperation> $operations */
    private function modify(string $dn, array $operations): void
    {
        $manager = $this->ldap->getEntryManager();
        if (!$manager instanceof EntryManager) {
            throw new BackendFailure('Das LDAP-Verzeichnis unterstützt keine Änderungen.');
        }
        $manager->applyOperations($dn, $operations);
    }

    private function guard(callable $operation, string $message = 'Das LDAP-Verzeichnis ist nicht erreichbar.'): mixed
    {
        try {
            return $operation();
        } catch (BackendFailure $failure) {
            throw $failure;
        } catch (ExceptionInterface|\ErrorException $exception) {
            throw new BackendFailure($message, previous: $exception);
        }
    }
}
