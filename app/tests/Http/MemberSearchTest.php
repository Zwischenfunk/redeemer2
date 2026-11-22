<?php

namespace App\Tests\Http;

final class MemberSearchTest extends HttpStackTestCase
{
    private const SEARCH = '/?section=member&action=search';

    /** @param array<string, string> $fields */
    private function search(Browser $browser, array $fields): string
    {
        $token = $browser->tokenFrom(self::SEARCH, 'member-search');
        $response = $browser->post(self::SEARCH, $fields + ['_csrf_token' => $token]);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame(['/wien'.self::SEARCH], $response->getHeaders(false)['location']);

        return $browser->get(self::SEARCH)->getContent();
    }

    /** @return list<int> */
    private function ids(string $html): array
    {
        preg_match_all('/<tr data-member-id="(\d+)"/', $html, $m);

        return array_map(intval(...), $m[1]);
    }

    public function testSearchRedirectsToSameUriAndListsMatchesFromTheSession(): void
    {
        $browser = $this->loggedIn('testadmin');
        $html = $this->search($browser, ['search' => 'testuser', 'limitresult' => '1']);

        self::assertSame([3], $this->ids($html));
        self::assertStringContainsString('<td>Uli User</td>', $html);
        self::assertSame([3], $this->ids($browser->get(self::SEARCH)->getContent()));
    }

    public function testSearchMatchesNameEmailAndNumericId(): void
    {
        $browser = $this->loggedIn('testsuper');
        self::assertSame([4], $this->ids($this->search($browser, ['search' => 'Olga Owner'])));
        self::assertSame([5], $this->ids($this->search($browser, ['search' => 'testtech@example'])));
        self::assertSame([6], $this->ids($this->search($browser, ['search' => '6', 'ingroups' => '32'])));
        self::assertContains(6, $this->ids($this->search($browser, ['search' => '6'])));
        self::assertSame([], $this->ids($this->search($browser, ['search' => 'nobodyhasthis'])));
    }

    public function testTooShortSearchStringIsReportedAndNumbersAndGroupsAreAllowed(): void
    {
        $browser = $this->loggedIn('testadmin');
        self::assertStringContainsString('Such-String zu kurz', $this->search($browser, ['search' => 'te']));
        self::assertStringContainsString('Such-String zu kurz', $this->search($browser, ['search' => 't%e%']));
        self::assertSame([7], $this->ids($this->search($browser, ['search' => '', 'ingroups' => '7'])));
    }

    public function testGroupFilters(): void
    {
        $browser = $this->loggedIn('testadmin');
        self::assertSame([1, 2, 3, 5], $this->ids($this->search($browser, ['search' => '', 'ingroups' => '3'])));
        self::assertSame([3], $this->ids($this->search($browser, ['search' => 'testu', 'ingroups' => '3, 3', 'offgroups' => '1,2,5'])));
        self::assertSame([1, 2, 3, 4, 5, 6, 8, 9], $this->ids($this->search($browser, ['search' => 'test', 'offgroups' => '7'])));
    }

    public function testInvalidGroupListIsRejectedAndNeverReachesTheSessionOrSql(): void
    {
        $browser = $this->loggedIn('testadmin');
        $this->search($browser, ['search' => 'testuser']);
        $token = $browser->tokenFrom(self::SEARCH, 'member-search');
        foreach (['1) OR (1=1', '3;4', 'a', '-1', '1,,2'] as $bad) {
            $response = $browser->post(self::SEARCH, ['search' => 'zzzzz', 'ingroups' => $bad, '_csrf_token' => $token]);
            self::assertSame(422, $response->getStatusCode(), $bad);
        }
        self::assertSame([3], $this->ids($browser->get(self::SEARCH)->getContent()));
    }

    public function testSearchTermIsBoundAsParameter(): void
    {
        $browser = $this->loggedIn('testadmin');
        self::assertSame([], $this->ids($this->search($browser, ['search' => "x') OR 1=1 --"])));
        self::assertSame([], $this->ids($this->search($browser, ['search' => "';DROP TABLE members;--"])));
        self::assertSame(9, (int) $this->db()->fetchOne('SELECT count(*) FROM members'));
    }

    public function testAdminSearchAlsoMatchesStreetSuperuserDoesNot(): void
    {
        self::assertSame([4, 5], $this->ids($this->search($this->loggedIn('testadmin'), ['search' => 'Probeweg'])));
        self::assertSame([], $this->ids($this->search($this->loggedIn('testsuper'), ['search' => 'Probeweg'])));
    }

    public function testDeleteIconOnlyForAdminWithoutNodesAndMembershipState(): void
    {
        $admin = $this->loggedIn('testadmin');
        $html = $this->search($admin, ['search' => 'test']);
        $icons = [];
        foreach (explode('<tr data-member-id="', $html) as $chunk) {
            if (1 === preg_match('/^(\d+)"/', $chunk, $m) && str_contains($chunk, 'data-action="delete"')) {
                $icons[] = (int) $m[1];
            }
        }
        // 6 is only Tech-C of a pending proposal (id < 0, not counted); 7 (role 7) and 9 have no state 3..6, so the icon shows although the server refuses 7. 8 has state 6.
        self::assertSame([6, 7, 9], $icons);

        self::assertStringNotContainsString('data-action="delete"', $this->search($this->loggedIn('testsuper'), ['search' => 'test']));
    }

    public function testListShowsMembershipStateNodeCountsAndTimestamps(): void
    {
        $html = $this->search($this->loggedIn('testadmin'), ['search' => 'testowner2']);
        $flat = preg_replace('/\s+/', ' ', $html);
        self::assertStringContainsString('<td> AO </td> <td>1/1</td> <td>01.01.2026</td>', $flat);
        self::assertStringContainsString('data-mail-status data-member-id="4" data-address="testowner2@example.test"', $html);
        self::assertStringContainsString('<span style="color: red">Admin</span>', preg_replace('/\s+/', ' ', $this->search($this->loggedIn('testadmin'), ['search' => 'testadmin'])));
    }

    public function testSearchIsOnlyForAdminAndSuperuserAndNeedsCsrf(): void
    {
        $user = $this->loggedIn('testuser');
        self::assertSame(403, $user->get(self::SEARCH)->getStatusCode());
        self::assertSame(403, $user->post(self::SEARCH, ['search' => 'abc', '_csrf_token' => 'x'])->getStatusCode());

        $admin = $this->loggedIn('testadmin');
        self::assertSame(403, $admin->post(self::SEARCH, ['search' => 'abc'])->getStatusCode());
        self::assertSame(403, $admin->post(self::SEARCH, ['search' => 'abc', '_csrf_token' => 'wrong'])->getStatusCode());
        self::assertSame(200, $admin->get(self::SEARCH)->getStatusCode());
        self::assertSame(403, $this->loggedIn('testuser')->get('/?section=member&action=insert')->getStatusCode());
    }

    public function testListIsLimitedTo30UnlessLimitIsOff(): void
    {
        $db = $this->db();
        for ($i = 0; $i < 40; ++$i) {
            $db->fetchOne("SELECT insertmember('bulk$i', 'Bulk', 'Member', 'Weg', '1', '1010', 'Wien', '', '', '', 'bulk$i@example.test', '')");
        }
        $admin = $this->loggedIn('testadmin');
        self::assertCount(30, $this->ids($this->search($admin, ['search' => 'bulk', 'limitresult' => '1'])));
        self::assertCount(40, $this->ids($this->search($admin, ['search' => 'bulk'])));
        self::assertDoesNotMatchRegularExpression('/name="limitresult"[^>]*checked/', $admin->get('/')->getContent());
        $this->search($admin, ['search' => 'bulk', 'limitresult' => '1']);
        self::assertMatchesRegularExpression('/name="limitresult"[^>]*checked/', $admin->get('/')->getContent());
    }
}
