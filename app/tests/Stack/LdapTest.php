<?php

namespace App\Tests\Stack;

use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;

final class LdapTest extends StackTestCase
{
    public function testServiceAccountFindsSingleUserByUid(): void
    {
        $entries = $this->bindAsService()
            ->query($this->baseDn(), '(&(objectClass=posixAccount)(uid=testuser))')
            ->execute()
            ->toArray();

        self::assertCount(1, $entries);
        self::assertSame(['3'], $entries[0]->getAttribute('uidNumber'));
    }

    public function testUserCanBindWithSeedPassword(): void
    {
        $this->ldap()->bind('uid=testuser,'.$this->baseDn(), 'rewrite-test-only');

        $this->expectNotToPerformAssertions();
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->expectException(ConnectionException::class);

        $this->ldap()->bind('uid=testuser,'.$this->baseDn(), 'wrong');
    }

    public function testUidNumbersMatchMemberIdsExceptForPreparedMismatches(): void
    {
        $uidNumbers = [];
        foreach ($this->bindAsService()->query($this->baseDn(), '(objectClass=posixAccount)')->execute() as $entry) {
            $uidNumbers[$entry->getAttribute('uid')[0]] = (int) $entry->getAttribute('uidNumber')[0];
        }
        $memberIds = $this->db()->fetchAllKeyValue('SELECT nickname, id FROM members');

        $mismatches = array_keys(array_diff_key($uidNumbers, $memberIds) + array_diff_key($memberIds, $uidNumbers));
        sort($mismatches);

        // LDAP-only account, pending account (uidNumber -1, no member row) and SQL-only member
        self::assertSame(['testldaponly', 'testpending', 'testsqlonly'], $mismatches);
        foreach (array_intersect_key($uidNumbers, $memberIds) as $nickname => $uidNumber) {
            self::assertSame($memberIds[$nickname], $uidNumber, $nickname);
        }
    }

    public function testObjectClassesMatchWhatTheOriginalFrontendWrites(): void
    {
        $entry = $this->bindAsService()
            ->query($this->baseDn(), '(&(objectClass=posixAccount)(uid=testuser))')
            ->execute()
            ->toArray()[0];

        $classes = $entry->getAttribute('objectClass');
        sort($classes);
        self::assertSame(['inetOrgPerson', 'organizationalPerson', 'person', 'posixAccount', 'top'], $classes);
    }

    public function testServiceAccountCanCreateChangePasswordFindByUidNumberAndDeleteAMember(): void
    {
        $ldap = $this->bindAsService();
        $manager = $ldap->getEntryManager();
        $entry = new Entry('uid=probe,'.$this->baseDn(), [
            'objectClass' => ['top', 'posixAccount', 'inetOrgPerson', 'organizationalPerson', 'person'],
            'uid' => ['probe'], 'cn' => ['Pia Probe'], 'sn' => ['Probe'], 'givenName' => ['Pia'],
            'mail' => ['probe@example.test'], 'userPassword' => ['first-pw'],
            // a freshly created account is "pending": the original frontend writes -1 until the member id is known
            'uidNumber' => ['-1'], 'gidNumber' => ['-1'], 'homeDirectory' => ['/home/probe'],
        ]);

        $manager->add($entry);
        try {
            $entry->setAttribute('userPassword', ['second-pw']);
            $manager->update($entry);
            $this->ldap()->bind($entry->getDn(), 'second-pw');

            $found = $this->bindAsService()->query($this->baseDn(), '(&(objectClass=posixAccount)(uidNumber=-1)(uid=probe))')->execute()->toArray();
            self::assertCount(1, $found);
        } finally {
            $this->bindAsService()->getEntryManager()->remove($entry);
        }

        self::assertCount(0, $this->bindAsService()->query($this->baseDn(), '(uid=probe)')->execute()->toArray());
    }
}
