<?php

namespace App\Tests\Http;

use App\Member\AccountMailer;
use App\Member\Actor;
use App\Member\BackendFailure;
use App\Member\MemberAccounts;
use App\Member\MemberDirectory;
use App\Member\MemberPolicy;
use App\Member\MemberQueries;
use App\Member\MemberRules;
use App\Member\MemberStore;
use App\Member\Profile;
use App\Member\RoleDelegation;
use App\Member\Status;
use Psr\Log\NullLogger;

/** Fachlogik with real PostgreSQL and LDAP, where single steps are forced to fail. */
final class MemberAccountsFailureTest extends HttpStackTestCase
{
    /** @param list<string> $failing names of port methods that throw instead of acting */
    private function accounts(array $failing = [], bool $welcomeMail = true): MemberAccounts
    {
        $real = self::getContainer()->get(MemberAccounts::class);
        $reach = new \ReflectionClass($real);
        $get = static fn (string $name): mixed => $reach->getProperty($name)->getValue($real);
        $fail = static function (string $method) use ($failing): void {
            if (in_array($method, $failing, true)) {
                throw new BackendFailure('Forced failure of '.$method.'.');
            }
        };

        $directory = new class($get('directory'), $fail) implements MemberDirectory {
            public function __construct(private MemberDirectory $real, private \Closure $fail)
            {
            }

            public function nicknameExists(string $nickname): bool
            {
                ($this->fail)('nicknameExists');

                return $this->real->nicknameExists($nickname);
            }

            public function exists(int $memberId): bool
            {
                ($this->fail)('exists');

                return $this->real->exists($memberId);
            }

            public function add(string $nickname, Profile $profile, #[\SensitiveParameter] string $password): void
            {
                ($this->fail)('add');
                $this->real->add($nickname, $profile, $password);
            }

            public function assignId(string $nickname, int $memberId): void
            {
                ($this->fail)('assignId');
                $this->real->assignId($nickname, $memberId);
            }

            public function update(int $memberId, #[\SensitiveParameter] array $attributes): void
            {
                ($this->fail)('directoryUpdate');
                $this->real->update($memberId, $attributes);
            }

            public function remove(int $memberId): void
            {
                ($this->fail)('remove');
                $this->real->remove($memberId);
            }
        };
        $store = new class($get('store'), $fail) implements MemberStore {
            public function __construct(private MemberStore $real, private \Closure $fail)
            {
            }

            public function insert(string $nickname, Profile $profile): int
            {
                ($this->fail)('insert');

                return $this->real->insert($nickname, $profile);
            }

            public function update(int $memberId, Profile $profile, array $roleChanges): void
            {
                ($this->fail)('storeUpdate');
                $this->real->update($memberId, $profile, $roleChanges);
            }

            public function delete(int $memberId): void
            {
                ($this->fail)('delete');
                $this->real->delete($memberId);
            }
        };
        $mailer = new class($get('mailer'), $fail) implements AccountMailer {
            public function __construct(private AccountMailer $real, private \Closure $fail)
            {
            }

            public function sendWelcome(string $to, string $nickname, #[\SensitiveParameter] string $password): void
            {
                ($this->fail)('sendWelcome');
                $this->real->sendWelcome($to, $nickname, $password);
            }

            public function sendReset(string $to, string $nickname, #[\SensitiveParameter] string $password): void
            {
                ($this->fail)('sendReset');
                $this->real->sendReset($to, $nickname, $password);
            }
        };

        return new MemberAccounts($directory, $store, $mailer, $get('queries'), $get('rules'), $get('policy'), $get('roles'), new NullLogger(), $welcomeMail);
    }

    private function admin(): Actor
    {
        return new Actor(1, 1, 'testadmin', [1, 3]);
    }

    /** @return array<string, string> */
    private function input(string $nickname = 'newbie'): array
    {
        return $this->newMember($nickname) + ['password' => 'Mein-Passwort-9'];
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string> the whole current form of member 3 with the changes applied
     */
    private function changed(array $changes): array
    {
        $profile = self::getContainer()->get(MemberQueries::class)->profile(3);

        return $changes + array_diff_key($profile->toArray(), ['nickname' => 1]);
    }

    private function memberCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT count(*) FROM members');
    }

    private function createReal(): int
    {
        $outcome = $this->accounts()->create($this->admin(), $this->input());
        self::assertSame(Status::Done, $outcome->status);

        return (int) $outcome->memberId;
    }

    public function testCreateWithFailingDirectoryChangesNothing(): void
    {
        $before = $this->memberCount();
        $outcome = $this->accounts(['add'])->create($this->admin(), $this->input());

        self::assertSame(Status::Failed, $outcome->status);
        self::assertSame($before, $this->memberCount());
        self::assertNull($this->ldapEntry('newbie'));
        self::assertSame([], $this->messages());
    }

    public function testCreateWithFailingNicknameCheckChangesNothing(): void
    {
        $outcome = $this->accounts(['nicknameExists'])->create($this->admin(), $this->input());

        self::assertSame(Status::Failed, $outcome->status);
        self::assertNull($this->ldapEntry('newbie'));
    }

    public function testCreateWithFailingInsertLeavesTheLdapAccountAndSaysSo(): void
    {
        $before = $this->memberCount();
        $outcome = $this->accounts(['insert'])->create($this->admin(), $this->input());

        self::assertSame(Status::Partial, $outcome->status);
        self::assertSame($before, $this->memberCount());
        self::assertSame(['-1'], $this->ldapEntry('newbie')['uidNumber']);
        self::assertStringContainsString('newbie', implode(' ', $outcome->messages));
        self::assertSame([], $this->messages());
    }

    public function testCreateWithFailingIdAssignmentIsPartialAndSendsNoMail(): void
    {
        $outcome = $this->accounts(['assignId'])->create($this->admin(), $this->input());

        self::assertSame(Status::Partial, $outcome->status);
        self::assertNotNull($outcome->memberId);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT count(*) FROM members WHERE nickname = 'newbie'"));
        self::assertSame([], $this->messages());
    }

    public function testCreateWithFailingMailIsPartialButAccountWorks(): void
    {
        $outcome = $this->accounts(['sendWelcome'])->create($this->admin(), $this->input());

        self::assertSame(Status::Partial, $outcome->status);
        self::assertTrue($this->canBindAs('newbie', 'Mein-Passwort-9'));
        self::assertStringNotContainsString('Mein-Passwort-9', implode(' ', $outcome->messages));
    }

    public function testCreateWithoutWelcomeMailSendsNothing(): void
    {
        $outcome = $this->accounts([], false)->create($this->admin(), $this->input());

        self::assertSame(Status::Done, $outcome->status);
        self::assertSame([], $this->messages());
    }

    public function testUpdateWithFailingDirectoryLeavesTheDatabaseUntouched(): void
    {
        $outcome = $this->accounts(['directoryUpdate'])->update($this->admin(), 3, $this->changed(['firstname' => 'Geändert']));

        self::assertSame(Status::Failed, $outcome->status);
        self::assertNotSame('Geändert', $this->db()->fetchOne('SELECT firstname FROM members WHERE id = 3'));
        self::assertNotSame(['Geändert'], $this->ldapEntry('testuser')['givenName']);
    }

    public function testUpdateWithFailingStoreAfterDirectoryChangeIsPartial(): void
    {
        $outcome = $this->accounts(['storeUpdate'])->update($this->admin(), 3, $this->changed(['firstname' => 'Geändert']));

        self::assertSame(Status::Partial, $outcome->status);
        self::assertSame(['Geändert'], $this->ldapEntry('testuser')['givenName']);
        self::assertNotSame('Geändert', $this->db()->fetchOne('SELECT firstname FROM members WHERE id = 3'));
    }

    public function testUpdateWithFailingStoreAndNoDirectoryChangeIsFailed(): void
    {
        $outcome = $this->accounts(['storeUpdate'])->update($this->admin(), 3, $this->changed(['town' => 'Graz']));

        self::assertSame(Status::Failed, $outcome->status);
    }

    public function testDeleteWithFailingDirectoryKeepsBoth(): void
    {
        $id = $this->createReal();
        $outcome = $this->accounts(['remove'])->delete($this->admin(), $id);

        self::assertSame(Status::Failed, $outcome->status);
        self::assertNotNull($this->ldapEntry('newbie'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM members WHERE id = ?', [$id]));
    }

    public function testDeleteWithFailingStoreAfterLdapRemovalIsPartial(): void
    {
        $id = $this->createReal();
        $outcome = $this->accounts(['delete'])->delete($this->admin(), $id);

        self::assertSame(Status::Partial, $outcome->status);
        self::assertNull($this->ldapEntry('newbie'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM members WHERE id = ?', [$id]));
    }

    public function testResetWithFailingDirectoryChangesNothingAndSendsNoMail(): void
    {
        $outcome = $this->accounts(['directoryUpdate'])->resetPassword('', 'testuser@example.test');

        self::assertSame(Status::Failed, $outcome->status);
        self::assertTrue($this->canBindAs('testuser', self::PASSWORD));
        self::assertSame([], $this->messages());
    }

    public function testResetWithFailingMailIsPartialAndPasswordIsChanged(): void
    {
        $outcome = $this->accounts(['sendReset'])->resetPassword('', 'testuser@example.test');

        self::assertSame(Status::Partial, $outcome->status);
        self::assertFalse($this->canBindAs('testuser', self::PASSWORD));
    }
}
