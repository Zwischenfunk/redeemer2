<?php

namespace App\Tests\Http;

final class MemberProfileTest extends HttpStackTestCase
{
    private function url(?int $id = null): string
    {
        return '/?section=member&action=update'.(null === $id ? '' : '&id='.$id);
    }

    /** @return array<string, string> a valid full form for the given member, taken from the rendered page */
    private function formValues(Browser $browser, ?int $id = null): array
    {
        $html = $browser->get($this->url($id))->getContent();
        preg_match_all('/<input id="f-(\w+)" name="\w+" value="([^"]*)"/', $html, $matches, PREG_SET_ORDER);
        $values = [];
        foreach ($matches as $match) {
            $values[$match[1]] = html_entity_decode($match[2]);
        }
        $values['_csrf_token'] = Browser::token($html, 'member-update');

        return $values;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->db()->fetchAssociative('SELECT * FROM members WHERE id = ?', [$id]);
    }

    public function testMemberSeesOwnProfileAsFormWithoutNamesAndRoles(): void
    {
        $html = $this->loggedIn('testplain')->get($this->url())->getContent();
        self::assertStringContainsString('name="street"', $html);
        self::assertStringNotContainsString('name="firstname"', $html);
        self::assertStringNotContainsString('name="nickname"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringNotContainsString('roles[admin]', $html);
    }

    public function testMemberSavesOwnContactDataInDatabaseAndLdap(): void
    {
        $browser = $this->loggedIn('testuser');
        $values = $this->formValues($browser);
        $values['telephone'] = '+43 1 5551234';
        $values['email'] = 'neu@example.test';
        $values['town'] = 'Graz';
        $response = $browser->post($this->url(), $values);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Die Daten wurden gespeichert.', $response->getContent());
        $row = $this->row(3);
        self::assertSame('+43 1 5551234', $row['telephone']);
        self::assertSame('Graz', $row['town']);
        self::assertSame('neu@example.test', $row['email']);
        self::assertSame(['neu@example.test'], $this->ldapEntry('testuser')['mail']);
        self::assertSame(['Uli User'], $this->ldapEntry('testuser')['cn']);
    }

    public function testMemberChangesOwnPasswordWithConfirmation(): void
    {
        $browser = $this->loggedIn('testuser');
        $values = $this->formValues($browser) + ['password' => 'Neues-Passwort-1', 'password2' => 'Neues-Passwort-1'];
        self::assertSame(200, $browser->post($this->url(), $values)->getStatusCode());

        self::assertTrue($this->canBindAs('testuser', 'Neues-Passwort-1'));
        self::assertFalse($this->canBindAs('testuser', self::PASSWORD));
    }

    public function testMismatchingPasswordConfirmationChangesNothing(): void
    {
        $browser = $this->loggedIn('testuser');
        $values = $this->formValues($browser);
        $values['town'] = 'Linz';
        $response = $browser->post($this->url(), $values + ['password' => 'abc12345', 'password2' => 'different']);

        self::assertSame(422, $response->getStatusCode());
        self::assertTrue($this->canBindAs('testuser', self::PASSWORD));
        self::assertNotSame('Linz', $this->row(3)['town']);
        self::assertStringContainsString('value="Linz"', $response->getContent(false));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'zip lowercase' => ['zip', '1a20'];
        yield 'zip short' => ['zip', '12'];
        yield 'housenumber letters' => ['housenumber', 'abc'];
        yield 'telephone letters' => ['telephone', '12ab'];
        yield 'email no domain' => ['email', 'nobody@'];
        yield 'email too long' => ['email', str_repeat('a', 45).'@example.test'];
        yield 'homepage scheme' => ['homepage', 'ftp://example.test'];
        yield 'street empty' => ['street', ''];
        yield 'town control character' => ['town', "Wien\x01"];
        yield 'street too long' => ['street', str_repeat('x', 101)];
    }

    /** @dataProvider invalidValues */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function testInvalidValuesAreRejectedWithoutAnyChange(string $field, string $value): void
    {
        $before = $this->row(3);
        $ldap = $this->ldapEntry('testuser');
        $browser = $this->loggedIn('testuser');
        $values = $this->formValues($browser);
        $values[$field] = $value;
        $response = $browser->post($this->url(), $values);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('aria-invalid="true"', $response->getContent(false));
        self::assertSame($before['town'], $this->row(3)['town']);
        self::assertSame($before['street'], $this->row(3)['street']);
        self::assertSame($ldap, $this->ldapEntry('testuser'));
    }

    public function testSaveNeedsValidCsrfToken(): void
    {
        $browser = $this->loggedIn('testuser');
        $values = $this->formValues($browser);
        $values['town'] = 'Linz';
        foreach (['wrong', ''] as $token) {
            self::assertSame(403, $browser->post($this->url(), ['_csrf_token' => $token] + $values)->getStatusCode());
        }
        unset($values['_csrf_token']);
        self::assertSame(403, $browser->post($this->url(), $values)->getStatusCode());
        self::assertNotSame('Linz', $this->row(3)['town']);
    }

    public function testMemberCannotReachOrChangeForeignProfiles(): void
    {
        $browser = $this->loggedIn('testuser');
        $token = Browser::token($browser->get($this->url())->getContent(), 'member-update');
        self::assertSame(403, $browser->get($this->url(4))->getStatusCode());
        self::assertSame(403, $browser->post($this->url(4), ['_csrf_token' => $token, 'town' => 'Linz'])->getStatusCode());
        self::assertNotSame('Linz', $this->row(4)['town']);
    }

    public function testMemberCannotSmuggleNamesNicknameOrRoles(): void
    {
        $browser = $this->loggedIn('testuser');
        $values = $this->formValues($browser);
        foreach ([['firstname' => 'Hacker'], ['nickname' => 'other'], ['roles' => ['admin' => '1']], ['unknown' => 'x']] as $extra) {
            self::assertSame(403, $browser->post($this->url(), $values + $extra)->getStatusCode(), json_encode($extra));
        }
        self::assertSame('Uli', $this->row(3)['firstname']);
        self::assertSame([3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 3')));
    }

    public function testAdminEditsForeignProfileIncludingNamesButNotPassword(): void
    {
        $browser = $this->loggedIn('testadmin');
        $values = $this->formValues($browser, 5);
        self::assertArrayHasKey('firstname', $values);
        $html = $browser->get($this->url(5))->getContent();
        self::assertStringNotContainsString('name="password"', $html);

        $values['firstname'] = 'Tina';
        $values['lastname'] = 'Müller';
        self::assertSame(200, $browser->post($this->url(5), $values)->getStatusCode());
        self::assertSame('Tina', $this->row(5)['firstname']);
        self::assertSame('Müller', $this->row(5)['lastname']);
        self::assertSame(['Tina'], $this->ldapEntry('testtech')['givenName']);
        self::assertSame(['Müller'], $this->ldapEntry('testtech')['sn']);
        self::assertSame(self::PASSWORD, $this->ldapEntry('testtech')['userPassword'][0]);

        $values['password'] = $values['password2'] = 'x12345678';
        self::assertSame(403, $browser->post($this->url(5), $values)->getStatusCode());
        self::assertTrue($this->canBindAs('testtech', self::PASSWORD));
    }

    public function testAdminNamesAreValidated(): void
    {
        $browser = $this->loggedIn('testadmin');
        $values = $this->formValues($browser, 5);
        $values['firstname'] = 'Tina2';
        self::assertSame(422, $browser->post($this->url(5), $values)->getStatusCode());
        self::assertNotSame('Tina2', $this->row(5)['firstname']);
    }

    public function testSuperuserGetsContactFormForForeignProfileAndOnlyThat(): void
    {
        $browser = $this->loggedIn('testsuper');
        $html = $browser->get($this->url(3))->getContent();
        foreach (['telephone', 'mobilephone', 'email'] as $field) {
            self::assertStringContainsString('name="'.$field.'"', $html);
        }
        foreach (['street', 'firstname', 'password', 'roles['] as $name) {
            self::assertStringNotContainsString('name="'.$name, $html);
        }

        $values = $this->formValues($browser, 3);
        $values['telephone'] = '0699 1234567';
        $values['email'] = 'su-changed@example.test';
        self::assertSame(200, $browser->post($this->url(3), $values)->getStatusCode());
        self::assertSame('0699 1234567', $this->row(3)['telephone']);
        self::assertSame(['su-changed@example.test'], $this->ldapEntry('testuser')['mail']);

        // Fields outside the contact form are refused, never silently dropped (U-2).
        self::assertSame(403, $browser->post($this->url(3), $values + ['street' => 'Hack 1'])->getStatusCode());
        self::assertSame(403, $browser->post($this->url(3), $values + ['password' => 'x', 'password2' => 'x'])->getStatusCode());
        self::assertSame(403, $browser->post($this->url(3), $values + ['roles' => ['admin' => '1']])->getStatusCode());
        self::assertSame(403, $browser->post($this->url(3), ['mini' => '0', 'street' => 'Hack 1', 'town' => 'X'] + $values)->getStatusCode());
        self::assertNotSame('Hack 1', $this->row(3)['street']);
    }

    public function testSuperuserCannotBecomeAdminForSelfOrOthers(): void
    {
        $browser = $this->loggedIn('testsuper');
        $values = $this->formValues($browser);
        self::assertSame(403, $browser->post($this->url(), $values + ['roles' => ['admin' => '1']])->getStatusCode());
        self::assertSame([2, 3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 2 ORDER BY 1')));
    }

    public function testAdminDelegatesAdminAndSuperuserRoles(): void
    {
        $browser = $this->loggedIn('testadmin');
        $values = $this->formValues($browser, 5);
        $values['roles'] = ['admin' => '0', 'superuser' => '2'];
        self::assertSame(200, $browser->post($this->url(5), $values)->getStatusCode());
        self::assertSame([2, 3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 5 ORDER BY 1')));

        $values['roles'] = ['admin' => '1', 'superuser' => '0'];
        self::assertSame(200, $browser->post($this->url(5), $values)->getStatusCode());
        self::assertSame([1, 3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 5 ORDER BY 1')));

        $values['roles'] = ['admin' => '5'];
        self::assertSame(422, $browser->post($this->url(5), $values)->getStatusCode());
        self::assertSame([1, 3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 5 ORDER BY 1')));
    }

    public function testRoleGroupsWithoutPostedKeyStayUntouched(): void
    {
        $browser = $this->loggedIn('testadmin');
        $values = $this->formValues($browser, 2);
        self::assertSame(200, $browser->post($this->url(2), $values)->getStatusCode());
        self::assertSame([2, 3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 2 ORDER BY 1')));
    }

    public function testMemberRoleGroupsFollowRequiredRoleAndSelfOnly(): void
    {
        $browser = $this->loggedIn('testuser');
        $html = $browser->get($this->url())->getContent();
        self::assertStringContainsString('name="roles[membership]"', $html);
        self::assertStringContainsString('name="roles[friend]"', $html);

        $values = $this->formValues($browser);
        $values['roles'] = ['membership' => '3', 'friend' => '6'];
        self::assertSame(200, $browser->post($this->url(), $values)->getStatusCode());
        self::assertSame([3, 6], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 3 ORDER BY 1')));

        $values['roles'] = ['membership' => '7'];
        self::assertSame(422, $browser->post($this->url(), $values)->getStatusCode());
        self::assertSame([3, 6], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 3 ORDER BY 1')));
    }

    public function testMemberWithoutRequiredRoleGetsNoDelegatedGroups(): void
    {
        $html = $this->loggedIn('testplain')->get($this->url())->getContent();
        self::assertStringNotContainsString('roles[membership]', $html);
        self::assertStringNotContainsString('roles[friend]', $html);
    }

    public function testImpersonatingAdminDoesNotGetRoleGroupsOfTheTarget(): void
    {
        $admin = $this->loggedIn('testadmin');
        self::assertStringContainsString('roles[membership]', $admin->get($this->url())->getContent());
        $admin->post($this->url(3), ['transform' => 'go', '_csrf_token' => $admin->tokenFrom($this->url(3), 'member-transform')]);
        $html = $admin->get($this->url())->getContent();
        self::assertStringNotContainsString('roles[membership]', $html);
        self::assertStringContainsString('roles[admin]', $html);

        $values = $this->formValues($admin);
        self::assertSame(403, $admin->post($this->url(), $values + ['roles' => ['membership' => '4']])->getStatusCode());
        self::assertSame([1, 3], array_map('intval', $this->db()->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = 1 ORDER BY 1')));
    }

    public function testUnknownMemberIsNotFoundForPrivilegedAndDeniedForOthers(): void
    {
        self::assertSame(404, $this->loggedIn('testadmin')->get($this->url(424242))->getStatusCode());
        self::assertSame(404, $this->loggedIn('testadmin')->get('/?section=member&action=update&id=abc')->getStatusCode());
        self::assertSame(403, $this->loggedIn('testuser')->get($this->url(424242))->getStatusCode());
        self::assertSame(404, $this->loggedIn('testuser')->get('/?section=member&action=bogus')->getStatusCode());
    }

    public function testMemberWithoutLdapAccountFailsLoudlyWithoutDatabaseChange(): void
    {
        $browser = $this->loggedIn('testadmin');
        $values = $this->formValues($browser, 9);
        $values['email'] = 'sqlonly-new@example.test';
        $response = $browser->post($this->url(9), $values);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('data-status="failed"', $response->getContent(false));
        self::assertNotSame('sqlonly-new@example.test', $this->row(9)['email']);
    }
}
