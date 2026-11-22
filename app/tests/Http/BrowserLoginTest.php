<?php

namespace App\Tests\Http;

use App\Tests\Stack\StackTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BrowserLoginTest extends StackTestCase
{
    private HttpClientInterface $http;

    protected function setUp(): void
    {
        $this->http = HttpClient::create(['max_redirects' => 0]);
    }

    public function testBrowserLoginRotatesSessionAndRecordsSuccessfulLogin(): void
    {
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 3');
        try {
            [$token, $cookies] = $this->loginPage();
            $response = $this->submit('TESTUSER', 'rewrite-test-only', $token, $cookies);

            self::assertSame(301, $response->getStatusCode());
            self::assertSame(['/wien/'], $response->getHeaders(false)['location']);
            self::assertSame(['User=testuser; Id=3'], $response->getHeaders(false)['x-login-ok']);
            self::assertStringContainsString('CSRFtoken=deleted;', implode("\n", $response->getHeaders(false)['set-cookie']));
            self::assertMatchesRegularExpression('/redeemer_wien=[^;]+; path=\/wien\/; httponly; samesite=strict/i', implode("\n", $response->getHeaders(false)['set-cookie']));
            $authenticated = $this->cookies($response)['redeemer_wien'];
            self::assertNotSame($cookies['redeemer_wien'], $authenticated);
            self::assertStringContainsString('Willkommen im Redeemer', $this->request('GET', '/', ['redeemer_wien' => $authenticated])->getContent());
            self::assertStringContainsString('name="password"', $this->request('GET', '/', $cookies)->getContent());
            self::assertNotSame($before, $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 3'));
        } finally {
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = 3', [$before]);
        }
    }

    public function testLegacyIndexLoginPreservesRequestedUri(): void
    {
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 3');
        try {
            $path = '/index.php?section=member&action=update';
            [$token, $cookies] = $this->loginPage($path);
            $login = $this->submit('testuser', 'rewrite-test-only', $token, $cookies, $path);
            self::assertSame(301, $login->getStatusCode());
            self::assertSame(['/wien'.$path], $login->getHeaders(false)['location']);
            self::assertStringContainsString('testuser@example.test', $this->request('GET', $path, $this->cookies($login))->getContent());
        } finally {
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = 3', [$before]);
        }
    }

    public function testCsrfMustMatchBothTheBrowserAndItsSession(): void
    {
        [$token, $cookies] = $this->loginPage();
        $credentialFields = ['nickname' => 'testuser', 'password' => 'rewrite-test-only'];
        foreach ([
            [$cookies, $credentialFields, 406],
            [['redeemer_wien' => $cookies['redeemer_wien']], $credentialFields + ['CSRFtoken' => $token], 406],
            [$cookies, $credentialFields + ['CSRFtoken' => 'mismatch'], 401],
            [['CSRFtoken' => $token], $credentialFields + ['CSRFtoken' => $token], 401],
        ] as [$requestCookies, $body, $status]) {
            $response = $this->request('POST', '/', $requestCookies, $body);
            self::assertSame($status, $response->getStatusCode());
            self::assertArrayNotHasKey('x-login-ok', $response->getHeaders(false));
        }
    }

    #[DataProvider('invalidLogins')]
    public function testInvalidCredentialsDoNotCreateIdentityOrRecordLogin(string $nickname, string $password): void
    {
        $this->assertRejected($nickname, $password);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLogins(): iterable
    {
        yield 'wrong password' => ['testuser', 'wrong'];
        yield 'empty password' => ['testuser', ''];
        yield 'missing SQL member' => ['testldaponly', 'rewrite-test-only'];
        yield 'negative LDAP uidNumber' => ['testpending', 'rewrite-test-only'];
        yield 'no LDAP account' => ['testsqlonly', 'rewrite-test-only'];
    }

    public function testMultipleLdapMatchesAreRejected(): void
    {
        $entry = new Entry('cn=Duplicate Browser Test,'.$this->baseDn(), [
            'objectClass' => ['posixAccount', 'inetOrgPerson'],
            'uid' => ['testuser'], 'uidNumber' => ['3'], 'gidNumber' => ['3'],
            'cn' => ['Duplicate Browser Test'], 'sn' => ['Test'],
            'homeDirectory' => ['/home/duplicate'], 'userPassword' => ['rewrite-test-only'],
        ]);
        $this->bindAsService()->getEntryManager()->add($entry);
        try {
            $this->assertRejected('testuser', 'rewrite-test-only');
        } finally {
            $this->bindAsService()->getEntryManager()->remove($entry);
        }
    }

    #[DataProvider('members')]
    public function testProfileAndNavigationUseMemberAndDatabaseRole(string $nickname, int $id, ?string $role): void
    {
        $profile = '/?section=member&action=update';
        $admin = '/?section=member&action=search';
        $anonymousProfile = $this->request('GET', $profile)->getContent();
        self::assertStringContainsString('name="password"', $anonymousProfile);
        self::assertStringNotContainsString('@example.test', $anonymousProfile);
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = ?', [$id]);
        try {
            [$token, $cookies] = $this->loginPage();
            $login = $this->submit($nickname, 'rewrite-test-only', $token, $cookies);
            self::assertSame(301, $login->getStatusCode());
            $cookies = $this->cookies($login);
            $home = $this->request('GET', '/', $cookies)->getContent();
            self::assertStringContainsString('Benutzerdaten', $home);
            self::assertStringContainsString('https://voip.funkfeuer.at/phonebook/', $home);
            if (null === $role) {
                self::assertStringNotContainsString('Mitgliederverwaltung', $home);
                self::assertSame(403, $this->request('GET', $admin, $cookies)->getStatusCode());
            } else {
                self::assertStringContainsString('Mitgliederverwaltung: '.$role, $home);
                self::assertSame(404, $this->request('GET', $admin, $cookies)->getStatusCode());
            }
            $html = $this->request('GET', $profile, $cookies)->getContent();
            self::assertStringContainsString($nickname.'@example.test', $html);
            self::assertSame(403, $this->request('GET', $profile.'&id=9', $cookies)->getStatusCode());
            self::assertSame(405, $this->request('POST', $profile, $cookies, ['firstname' => 'Ignored'])->getStatusCode());
        } finally {
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = ?', [$before, $id]);
        }
    }

    /** @return iterable<string, array{string, int, ?string}> */
    public static function members(): iterable
    {
        yield 'member' => ['testuser', 3, null];
        yield 'admin' => ['testadmin', 1, 'Admin'];
        yield 'superuser' => ['testsuper', 2, 'Superuser'];
    }

    public function testOnlyPostWithCsrfLogsOut(): void
    {
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 3');
        try {
            [$token, $cookies] = $this->loginPage();
            $login = $this->submit('testuser', 'rewrite-test-only', $token, $cookies);
            self::assertSame(301, $login->getStatusCode());
            $cookies = $this->cookies($login);
            $logout = '/?logoff=true';
            self::assertSame(405, $this->request('GET', $logout, $cookies)->getStatusCode());
            self::assertSame(403, $this->request('POST', $logout, $cookies, ['_csrf_token' => 'wrong'])->getStatusCode());
            $home = $this->request('GET', '/', $cookies)->getContent();
            self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $home, $match));
            $response = $this->request('POST', $logout, $cookies, ['_csrf_token' => $match[1]]);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame(['/wien/'], $response->getHeaders(false)['location']);
            self::assertStringContainsString('name="password"', $this->request('GET', '/', $cookies)->getContent());
        } finally {
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = 3', [$before]);
        }
    }

    private function assertRejected(string $nickname, string $password): void
    {
        $before = $this->db()->fetchAllKeyValue('SELECT id, last_login FROM members ORDER BY id');
        try {
            [$token, $cookies] = $this->loginPage();
            $response = $this->submit($nickname, $password, $token, $cookies);
            self::assertSame(200, $response->getStatusCode());
            self::assertArrayNotHasKey('x-login-ok', $response->getHeaders());
            self::assertSame(['User/Password error'], $response->getHeaders()['x-login-failed']);
            self::assertStringContainsString('User/Password missmatch', $response->getContent());
            $afterCookies = $this->cookies($response) + $cookies;
            self::assertStringContainsString('name="password"', $this->request('GET', '/', $afterCookies)->getContent());
            self::assertSame($before, $this->db()->fetchAllKeyValue('SELECT id, last_login FROM members ORDER BY id'));
        } finally {
            foreach (array_diff_assoc($this->db()->fetchAllKeyValue('SELECT id, last_login FROM members ORDER BY id'), $before) as $id => $changed) {
                $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = ?', [$before[$id], $id]);
            }
        }
    }

    public function testDashboardShowsOwnerTechCAndIpStatistics(): void
    {
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 4');
        try {
            [$token, $cookies] = $this->loginPage();
            $login = $this->submit('testowner2', 'rewrite-test-only', $token, $cookies);
            self::assertSame(301, $login->getStatusCode());
            $home = $this->request('GET', '/', $this->cookies($login))->getContent();
            self::assertStringContainsString('außerordentliches Mitglied', $home);
            self::assertStringContainsString('Der Verein besteht derzeit aus 6 Mitgliedern', $home);
            self::assertStringContainsString('Eigene Nodes', $home);
            self::assertStringContainsString('Tech-C', $home);
            self::assertStringContainsString('Devices (v4)', $home);
            self::assertStringContainsString('Devices (v6)', $home);
            self::assertStringContainsString('Summe', $home);
            self::assertStringContainsString('0%', $home);
        } finally {
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = 4', [$before]);
        }
    }

    public function testAdminWinsOverSuperuserAndRoleRevocationInvalidatesSession(): void
    {
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 1');
        $this->db()->executeStatement('INSERT INTO members_roles (id_members, id_roles) VALUES (1, 2)');
        try {
            [$token, $cookies] = $this->loginPage();
            $login = $this->submit('testadmin', 'rewrite-test-only', $token, $cookies);
            self::assertSame(301, $login->getStatusCode());
            $cookies = $this->cookies($login);
            self::assertStringContainsString('Mitgliederverwaltung: Admin', $this->request('GET', '/', $cookies)->getContent());
            $this->db()->executeStatement('DELETE FROM members_roles WHERE id_members = 1 AND id_roles = 1');
            self::assertStringContainsString('name="password"', $this->request('GET', '/', $cookies)->getContent());
        } finally {
            $this->db()->executeStatement('INSERT INTO members_roles (id_members, id_roles) VALUES (1, 1) ON CONFLICT DO NOTHING');
            $this->db()->executeStatement('DELETE FROM members_roles WHERE id_members = 1 AND id_roles = 2');
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = 1', [$before]);
        }
    }

    public function testDashboardShowsPendingProposalAndNoNodes(): void
    {
        $before = $this->db()->fetchOne('SELECT last_login FROM members WHERE id = 6');
        try {
            [$token, $cookies] = $this->loginPage();
            $login = $this->submit('testplain', 'rewrite-test-only', $token, $cookies);
            self::assertSame(301, $login->getStatusCode());
            $home = $this->request('GET', '/', $this->cookies($login))->getContent();
            self::assertStringContainsString('Antragsformular', $home);
            self::assertStringContainsString('Du hast 1 offenen Vorschlag', $home);
            self::assertStringContainsString('Du hast noch keine Knoten angelegt.', $home);
            self::assertStringNotContainsString('Der Verein besteht derzeit', $home);
        } finally {
            $this->db()->executeStatement('UPDATE members SET last_login = ? WHERE id = 6', [$before]);
        }
    }

    /** @return array{string, array<string, string>} */
    private function loginPage(string $path = '/'): array
    {
        $response = $this->request('GET', $path);
        self::assertSame(1, preg_match('/name="CSRFtoken" value="([^"]+)"/', $response->getContent(), $match));
        $cookies = $this->cookies($response);
        self::assertSame($match[1], $cookies['CSRFtoken']);

        return [$match[1], $cookies];
    }

    /** @param array<string, string> $cookies */
    private function submit(string $nickname, string $password, string $token, array $cookies, string $path = '/'): ResponseInterface
    {
        return $this->request('POST', $path, $cookies, ['nickname' => $nickname, 'password' => $password, 'CSRFtoken' => $token]);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $cookies = [], array $body = []): ResponseInterface
    {
        $header = [];
        foreach ($cookies as $name => $value) {
            $header[] = $name.'='.$value;
        }

        return $this->http->request($method, (string) getenv('HTTP_BASE_URL').$path, [
            'headers' => ['Cookie' => implode('; ', $header)],
            'body' => $body,
        ]);
    }

    /** @return array<string, string> */
    private function cookies(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            [$name, $value] = explode('=', explode(';', $cookie, 2)[0], 2);
            $cookies[$name] = $value;
        }

        return $cookies;
    }
}
