<?php

namespace App\Tests\Stack;

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
}
