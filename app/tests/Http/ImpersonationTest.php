<?php

namespace App\Tests\Http;

final class ImpersonationTest extends HttpStackTestCase
{
    private const PROFILE = '/?section=member&action=update';

    private function transform(Browser $browser, int $id, ?string $token = null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $token ??= $browser->tokenFrom(self::PROFILE.'&id='.$id, 'member-transform');

        return $browser->post(self::PROFILE.'&id='.$id, ['transform' => 'go', '_csrf_token' => $token]);
    }

    public function testAdminSwitchesToMemberAndSeesThatIdentityVisibly(): void
    {
        $admin = $this->loggedIn('testadmin');
        self::assertStringContainsString('Transform me to testuser', $admin->get(self::PROFILE.'&id=3')->getContent());

        $switch = $this->transform($admin, 3);
        self::assertSame(303, $switch->getStatusCode());
        self::assertSame(['/wien/?section=nodes'], $switch->getHeaders(false)['location']);

        $home = $admin->get('/')->getContent();
        self::assertStringContainsString('data-impersonating="testuser"', $home);
        self::assertStringContainsString('testadmin', $home);
        self::assertStringContainsString('testuser@example.test', $home);
        self::assertStringNotContainsString('testadmin@example.test', $home);
        self::assertStringContainsString('<dd>testuser</dd>', $admin->get(self::PROFILE)->getContent());
    }

    public function testAdminKeepsAdminPowersAndDoesNotInheritTargetRoles(): void
    {
        $admin = $this->loggedIn('testadmin');
        $this->transform($admin, 7);

        self::assertSame(200, $admin->get('/?section=member&action=search')->getStatusCode());
        $home = $admin->get('/')->getContent();
        self::assertStringContainsString('Mitgliederverwaltung: Admin', $home);
        self::assertStringContainsString('Ehrenmitglied', $home);
    }

    public function testReturnToOriginalIdentity(): void
    {
        $admin = $this->loggedIn('testadmin');
        $this->transform($admin, 3);
        self::assertStringContainsString('Transform me to testadmin', $admin->get(self::PROFILE.'&id=1')->getContent());

        $back = $admin->post('/?section=member&action=update&id=1', [
            'transform' => 'go',
            '_csrf_token' => Browser::token($admin->get('/')->getContent(), 'impersonation-return'),
        ]);
        self::assertSame(303, $back->getStatusCode());
        $home = $admin->get('/')->getContent();
        self::assertStringNotContainsString('data-impersonating', $home);
        self::assertStringContainsString('testadmin@example.test', $home);
    }

    public function testSwitchingBetweenTargetsKeepsTheOriginalIdentity(): void
    {
        $admin = $this->loggedIn('testadmin');
        $this->transform($admin, 3);
        $this->transform($admin, 4);
        $home = $admin->get('/')->getContent();
        self::assertStringContainsString('data-impersonating="testowner2"', $home);
        self::assertStringContainsString('testadmin', $home);
    }

    public function testOnlyAdminsMayTransformAndNothingChangesOtherwise(): void
    {
        foreach (['testsuper', 'testuser'] as $nickname) {
            $browser = $this->loggedIn($nickname);
            $response = $browser->post(self::PROFILE.'&id=3', ['transform' => 'go', '_csrf_token' => 'anything']);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringNotContainsString('data-impersonating', $browser->get('/')->getContent());
        }
    }

    public function testTransformNeedsValidCsrfTokenAndExistingMember(): void
    {
        $admin = $this->loggedIn('testadmin');
        self::assertSame(403, $this->transform($admin, 3, 'wrong')->getStatusCode());
        self::assertStringNotContainsString('data-impersonating', $admin->get('/')->getContent());
        $token = $admin->tokenFrom(self::PROFILE.'&id=3', 'member-transform');
        self::assertSame(404, $this->transform($admin, 424242, $token)->getStatusCode());
        self::assertSame(405, $admin->request('PUT', self::PROFILE.'&id=3')->getStatusCode());
    }

    public function testImpersonationEndsWithTheSession(): void
    {
        $admin = $this->loggedIn('testadmin');
        $this->transform($admin, 3);
        $token = Browser::token($admin->get('/')->getContent(), 'logout');
        self::assertSame(303, $admin->post('/?logoff=true', ['_csrf_token' => $token])->getStatusCode());
        $admin->login('testadmin');
        self::assertStringNotContainsString('data-impersonating', $admin->get('/')->getContent());
    }
}
