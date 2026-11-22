<?php

namespace App\Tests\Http;

final class MemberCreateTest extends HttpStackTestCase
{
    public function testAdminCreatesMemberInLdapAndDatabaseAndMailsTheCredentials(): void
    {
        $admin = $this->loggedIn('testadmin');
        $response = $this->createMember($admin, ['nickname' => 'Newbie', 'email' => 'newbie@example.test'] + $this->newMember());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-status="done"', $response->getContent());
        $row = $this->db()->fetchAssociative("SELECT * FROM members WHERE nickname = 'newbie'");
        self::assertNotFalse($row);
        self::assertSame('Neugasse', $row['street']);
        self::assertSame('12a', $row['housenumber']);
        self::assertNotNull($row['created']);
        self::assertSame([], $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = ?', [$row['id']]));

        $entry = $this->ldapEntry('newbie');
        self::assertSame([(string) $row['id']], $entry['uidNumber']);
        self::assertSame([(string) $row['id']], $entry['gidNumber']);
        self::assertSame(['/home/newbie'], $entry['homeDirectory']);
        self::assertSame(['Nina Neu'], $entry['cn']);
        self::assertSame(['newbie@example.test'], $entry['mail']);

        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame('Frontend Zugangsdaten', $messages[0]['Subject']);
        self::assertSame('newbie@example.test', $messages[0]['To'][0]['Address']);
        self::assertSame('redeemer@example.test', $messages[0]['From']['Address']);
        $text = $this->messageText($messages[0]['ID']);
        self::assertStringContainsString('Nickname: newbie', $text);
        self::assertStringContainsString('https://portal.example.test/wien/', $text);
        self::assertSame(1, preg_match('/Passwort: (\S{8})\r?\n/', $text, $match), $text);
        self::assertMatchesRegularExpression('/^[0-9bcdfghjkmnpqrstvwxyzABCDEFGHJKLMNPQRSTUVWXYZ@:\-()!$%]{8}$/', $match[1]);

        $new = new Browser();
        self::assertSame(301, $new->login('newbie', $match[1])->getStatusCode());
        self::assertStringContainsString('newbie@example.test', $new->get('/')->getContent());
    }

    public function testExplicitPasswordIsUsedAndMailed(): void
    {
        $response = $this->createMember($this->loggedIn('testsuper'), $this->newMember() + ['password' => 'Mein-Passwort-9']);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->canBindAs('newbie', 'Mein-Passwort-9'));
        self::assertStringContainsString('Passwort: Mein-Passwort-9', $this->messageText($this->messages()[0]['ID']));
    }

    public function testOnlyAdminAndSuperuserMayCreate(): void
    {
        $user = $this->loggedIn('testuser');
        self::assertSame(403, $user->get('/?section=member&action=insert')->getStatusCode());
        self::assertSame(403, $user->post('/?section=member&action=insert', $this->newMember() + ['_csrf_token' => 'x'])->getStatusCode());
        self::assertSame(200, $this->loggedIn('testsuper')->get('/?section=member&action=insert')->getStatusCode());
        self::assertNull($this->ldapEntry('newbie'));
        self::assertSame([], $this->messages());
    }

    public function testCreateNeedsCsrfTokenAndRejectsUnknownFields(): void
    {
        $admin = $this->loggedIn('testadmin');
        self::assertSame(403, $admin->post('/?section=member&action=insert', $this->newMember())->getStatusCode());
        self::assertSame(403, $admin->post('/?section=member&action=insert', $this->newMember() + ['_csrf_token' => 'wrong'])->getStatusCode());
        self::assertSame(403, $this->createMember($admin, $this->newMember() + ['roles' => ['admin' => '1']])->getStatusCode());
        self::assertSame(403, $this->createMember($admin, $this->newMember() + ['id' => '77'])->getStatusCode());
        self::assertNull($this->ldapEntry('newbie'));
        self::assertSame(9, (int) $this->db()->fetchOne('SELECT count(*) FROM members'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'nickname with space' => ['nickname', 'new bie'];
        yield 'nickname umlaut' => ['nickname', 'nöbie'];
        yield 'nickname too long' => ['nickname', str_repeat('a', 26)];
        yield 'nickname ldap injection' => ['nickname', 'x,ou=evil'];
        yield 'nickname existing' => ['nickname', 'TestUser'];
        yield 'nickname only in ldap' => ['nickname', 'testldaponly'];
        yield 'firstname digit' => ['firstname', 'Ni2na'];
        yield 'lastname empty' => ['lastname', ''];
        yield 'email invalid' => ['email', 'not-an-address'];
        yield 'email with header injection' => ['email', "a@example.test\r\nBcc: x@example.test"];
        yield 'zip invalid' => ['zip', 'ab'];
        yield 'password control character' => ['password', "pass\x07word"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidInputChangesNothing(string $field, string $value): void
    {
        $before = [$this->db()->fetchOne('SELECT count(*) FROM members'), $this->bindAsService()->query($this->baseDn(), '(objectClass=posixAccount)')->execute()->count()];
        $response = $this->createMember($this->loggedIn('testadmin'), [$field => $value] + $this->newMember());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, [$this->db()->fetchOne('SELECT count(*) FROM members'), $this->bindAsService()->query($this->baseDn(), '(objectClass=posixAccount)')->execute()->count()]);
        self::assertSame([], $this->messages());
    }

    public function testValidationErrorKeepsTheEnteredValues(): void
    {
        $response = $this->createMember($this->loggedIn('testadmin'), ['zip' => 'ab'] + $this->newMember());
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('value="Neugasse"', $response->getContent(false));
        self::assertStringContainsString('Postleitzahl ungültig.', $response->getContent(false));
    }

    public function testCreatedMemberCanSignInAndSeesOwnProfile(): void
    {
        $this->createMember($this->loggedIn('testadmin'), $this->newMember() + ['password' => 'Mein-Passwort-9']);
        $new = $this->loggedInWith('newbie', 'Mein-Passwort-9');
        self::assertStringContainsString('name="street"', $new->get('/?section=member&action=update')->getContent());
        self::assertSame(403, $new->get('/?section=member&action=search')->getStatusCode());
    }
}
