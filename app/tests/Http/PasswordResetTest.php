<?php

namespace App\Tests\Http;

final class PasswordResetTest extends HttpStackTestCase
{
    private function formToken(Browser $browser, string $query = ''): string
    {
        $html = $browser->get('/?section=pwdreset'.$query)->getContent(false);
        self::assertSame(1, preg_match('/name="CSRFtoken" value="([^"]+)"/', $html, $match));

        return $match[1];
    }

    /** @param array<string, string> $values */
    private function reset(Browser $browser, array $values): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $browser->post('/?section=pwdreset&action=reset', $values + ['CSRFtoken' => $this->formToken($browser)]);
    }

    public function testFormOffersEmailOrPrefilledNickname(): void
    {
        $page = (new Browser())->get('/?section=pwdreset');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('name="email"', $page->getContent(false));
        self::assertStringNotContainsString('name="nickname"', $page->getContent(false));
        $prefilled = (new Browser())->get('/?section=pwdreset&nickname=testuser')->getContent(false);
        self::assertStringContainsString('name="nickname" value="testuser"', $prefilled);
        self::assertStringContainsString('?section=pwdreset', (new Browser())->get('/')->getContent(false));
    }

    public function testResetByEmailChangesLdapPasswordAndMailsIt(): void
    {
        $response = $this->reset(new Browser(), ['email' => 'TestUser@example.test']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-status="done"', $response->getContent(false));
        self::assertFalse($this->canBindAs('testuser', 'rewrite-test-only'));
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame('Frontend Zugangsdaten', $messages[0]['Subject']);
        self::assertSame('testuser@example.test', $messages[0]['To'][0]['Address']);
        $text = $this->messageText($messages[0]['ID']);
        self::assertStringContainsString('This is your new password:', $text);
        self::assertSame(1, preg_match('/This is your new password:\s*(\S{8})\s/', $text, $match), $text);
        self::assertTrue($this->canBindAs('testuser', $match[1]));
        self::assertSame(301, (new Browser())->login('testuser', $match[1])->getStatusCode());
    }

    public function testResetByNickname(): void
    {
        $browser = new Browser();
        $token = $this->formToken($browser, '&nickname=testplain');
        $response = $browser->post('/?section=pwdreset&action=reset', ['nickname' => 'TestPlain', 'CSRFtoken' => $token]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->canBindAs('testplain', 'rewrite-test-only'));
        self::assertCount(1, $this->messages());
    }

    public function testTokenIsSingleUse(): void
    {
        $browser = new Browser();
        $token = $this->formToken($browser);
        self::assertSame(200, $browser->post('/?section=pwdreset&action=reset', ['email' => 'testuser@example.test', 'CSRFtoken' => $token])->getStatusCode());
        self::assertSame(401, $browser->post('/?section=pwdreset&action=reset', ['email' => 'testplain@example.test', 'CSRFtoken' => $token])->getStatusCode());
        self::assertCount(1, $this->messages());
    }

    public function testMissingOrWrongTokenIsRefusedWithoutChange(): void
    {
        $browser = new Browser();
        $token = $this->formToken($browser);
        self::assertSame(406, (new Browser())->post('/?section=pwdreset&action=reset', ['email' => 'testuser@example.test', 'CSRFtoken' => $token])->getStatusCode());
        self::assertSame(406, $browser->post('/?section=pwdreset&action=reset', ['email' => 'testuser@example.test'])->getStatusCode());
        self::assertSame(401, $browser->post('/?section=pwdreset&action=reset', ['email' => 'testuser@example.test', 'CSRFtoken' => 'wrong'])->getStatusCode());
        self::assertTrue($this->canBindAs('testuser', 'rewrite-test-only'));
        self::assertSame([], $this->messages());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function refused(): iterable
    {
        yield 'unknown email' => [['email' => 'nobody@example.test']];
        yield 'unknown nickname' => [['nickname' => 'nobody']];
        yield 'empty' => [['email' => '']];
        yield 'member without ldap account' => [['nickname' => 'testsqlonly']];
        yield 'ldap-only account' => [['nickname' => 'testldaponly']];
        yield 'wildcard' => [['email' => '%@example.test']];
        yield 'ldap filter characters' => [['nickname' => 'testuser)(uid=*']];
    }

    /** @param array<string, string> $values */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testNoMatchChangesNothing(array $values): void
    {
        $response = $this->reset(new Browser(), $values);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('data-status="rejected"', $response->getContent(false));
        self::assertTrue($this->canBindAs('testuser', 'rewrite-test-only'));
        self::assertSame([], $this->messages());
    }

    public function testAmbiguousEmailChangesNothing(): void
    {
        $this->db()->executeStatement("UPDATE members SET email = 'testuser@example.test' WHERE id = 6");
        $response = $this->reset(new Browser(), ['email' => 'testuser@example.test']);

        self::assertSame(422, $response->getStatusCode());
        self::assertTrue($this->canBindAs('testuser', 'rewrite-test-only'));
        self::assertTrue($this->canBindAs('testplain', 'rewrite-test-only'));
        self::assertSame([], $this->messages());
    }

    public function testMemberWithoutValidEmailCannotBeReset(): void
    {
        $this->db()->executeStatement("UPDATE members SET email = '' WHERE id = 3");
        $response = $this->reset(new Browser(), ['nickname' => 'testuser']);

        self::assertSame(422, $response->getStatusCode());
        self::assertTrue($this->canBindAs('testuser', 'rewrite-test-only'));
    }

    public function testPageIsNotAvailableWhenSignedIn(): void
    {
        $user = $this->loggedIn('testuser');
        self::assertSame(404, $user->get('/?section=pwdreset')->getStatusCode());
        self::assertSame(405, $user->post('/?section=pwdreset&action=reset', ['email' => 'testplain@example.test', 'CSRFtoken' => 'x'])->getStatusCode());
        self::assertTrue($this->canBindAs('testplain', 'rewrite-test-only'));
    }
}
