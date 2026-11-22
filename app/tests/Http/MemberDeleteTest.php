<?php

namespace App\Tests\Http;

final class MemberDeleteTest extends HttpStackTestCase
{
    private function delete(Browser $browser, int $id, ?string $token = null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $url = '/?section=member&action=delete&id='.$id;
        $token ??= $browser->tokenFrom($url, 'member-delete');

        return $browser->post($url, ['_csrf_token' => $token]);
    }

    /** @return array{bool, bool} */
    private function exists(int $id, string $nickname): array
    {
        return [false !== $this->db()->fetchOne('SELECT 1 FROM members WHERE id = ?', [$id]), null !== $this->ldapEntry($nickname)];
    }

    public function testAdminDeletesMemberWithoutStateFromLdapAndDatabase(): void
    {
        // No seed member is deletable (member 6 is Tech-C of proposal node -1, which legacy counts), so create one.
        $admin = $this->loggedIn('testadmin');
        $this->createMember($admin, $this->newMember());
        $id = (int) $this->db()->fetchOne("SELECT id FROM members WHERE nickname = 'newbie'");
        self::assertGreaterThan(9, $id);

        $confirm = $admin->get('/?section=member&action=delete&id='.$id);
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('newbie', $confirm->getContent());
        self::assertSame([true, true], $this->exists($id, 'newbie'), 'GET must not delete');

        $response = $this->delete($admin, $id);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Das Mitglied wurde gelöscht.', $response->getContent());
        self::assertSame([false, false], $this->exists($id, 'newbie'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM members_roles WHERE id_members = ?', [$id]));
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function blocked(): iterable
    {
        yield 'membership role' => [8, 'testfriend', 'Mitgliedsstatus'];
        yield 'honorary role' => [7, 'testhonor', 'Mitgliedsstatus'];
        yield 'owner of nodes' => [4, 'testowner2', 'Mitgliedsstatus'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blocked')]
    public function testDeleteIsRefusedWhileMembershipOrNodesRemain(int $id, string $nickname, string $reason): void
    {
        $response = $this->delete($this->loggedIn('testadmin'), $id);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString($reason, $response->getContent(false));
        self::assertSame([true, true], $this->exists($id, $nickname));
    }

    public function testMemberWithNodesButNoRoleIsRefused(): void
    {
        $response = $this->delete($this->loggedIn('testadmin'), 6);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Nodes', $response->getContent(false));
        self::assertSame([true, true], $this->exists(6, 'testplain'));
    }

    public function testMemberWithoutLdapAccountIsDeletedFromDatabase(): void
    {
        $response = $this->delete($this->loggedIn('testadmin'), 9);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([false, false], $this->exists(9, 'testsqlonly'));
    }

    public function testOwnAccountAndImpersonatedAccountCannotBeDeleted(): void
    {
        $admin = $this->loggedIn('testadmin');
        self::assertSame(409, $this->delete($admin, 1)->getStatusCode());
        $admin->post('/?section=member&action=update&id=6', ['transform' => 'go', '_csrf_token' => $admin->tokenFrom('/?section=member&action=update&id=6', 'member-transform')]);
        $response = $this->delete($admin, 6);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Identitätswechsel', $response->getContent(false));
    }

    public function testOnlyAdminsMayDeleteAndTheTokenIsChecked(): void
    {
        $super = $this->loggedIn('testsuper');
        self::assertSame(403, $super->get('/?section=member&action=delete&id=6')->getStatusCode());
        self::assertSame(403, $super->post('/?section=member&action=delete&id=6', ['_csrf_token' => 'x'])->getStatusCode());

        $admin = $this->loggedIn('testadmin');
        self::assertSame(403, $this->delete($admin, 6, 'wrong')->getStatusCode());
        self::assertSame(403, $admin->post('/?section=member&action=delete&id=6')->getStatusCode());
        self::assertSame(404, $this->delete($admin, 424242, $admin->tokenFrom('/?section=member&action=delete&id=6', 'member-delete'))->getStatusCode());
        self::assertSame([true, true], $this->exists(6, 'testplain'));
    }
}
