<?php

namespace App\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;

/** PAR-NODE-05 to 09, 14 and 16: create, change, delete and Tech-C resignation, with the rights of each role. */
final class NodeCrudTest extends NodeTestCase
{
    /** PAR-NODE-05 */
    public function testInsertFormOffersNameCoordinatesDecimalHelpersAndMap(): void
    {
        $friend = $this->loggedIn('testfriend');
        $html = $friend->get(self::NODES.'&action=insert')->getContent();

        foreach (['name', 'gps_lat_deg', 'gps_lat_min', 'gps_lat_sec', 'gps_lon_deg', 'gps_lon_min', 'gps_lon_sec'] as $field) {
            self::assertStringContainsString('name="'.$field.'" value=""', $html);
        }
        self::assertStringContainsString('name="map" value="on" checked', $html);
        self::assertStringContainsString('data-decimal="lat"', $html);
        self::assertStringContainsString('data-decimal="lon"', $html);
        self::assertStringContainsString('src="/wien/assets/nodes.js"', $html);

        $script = $friend->get('/assets/nodes.js');
        self::assertSame(200, $script->getStatusCode());
        self::assertStringContainsString('javascript', $script->getHeaders()['content-type'][0]);
        self::assertStringContainsString('function decimalToDms', $script->getContent());
    }

    /** PAR-NODE-06, PAR-HOME-06 */
    public function testMemberCreatesNodeAsOwnerAndTechContact(): void
    {
        $friend = $this->loggedIn('testfriend');
        self::assertStringContainsString('Du hast noch keine Knoten angelegt.', $friend->get('/')->getContent());

        // A posted owner is ignored: the node always belongs to the member who creates it.
        $response = $this->createNode($friend, ['id_members' => '4', 'id_tech_c' => '4']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-status="done"', $response->getContent());
        self::assertStringContainsString('Der Node &quot;probe7&quot; wurde angelegt.', $response->getContent());

        $node = $this->db()->fetchAssociative("SELECT * FROM nodes WHERE name = 'probe7'");
        self::assertGreaterThan(4, $node['id']);
        self::assertSame([8, 8], $this->row($node['id']));
        self::assertSame([48.0, 12.0, 30.5, 16.0, 21.0, 0.0], array_map(floatval(...), [$node['gps_lat_deg'], $node['gps_lat_min'], $node['gps_lat_sec'], $node['gps_lon_deg'], $node['gps_lon_min'], $node['gps_lon_sec']]));
        self::assertTrue($node['map']);
        self::assertNotNull($node['created']);

        $row = self::nodeRow($friend->get(self::NODES)->getContent(), 'owned', $node['id']);
        self::assertStringContainsString('probe7', $row);
        self::assertStringContainsString('48.2085<br>16.3500', $row);
        // The home page counts the new node.
        $home = $friend->get('/')->getContent();
        self::assertStringContainsString('Eigene Nodes', $home);
        self::assertStringNotContainsString('Du hast noch keine Knoten angelegt.', $home);
    }

    public function testNodeWithoutCoordinatesAndMapKeepsTheNameAsEntered(): void
    {
        $friend = $this->loggedIn('testfriend');
        $response = $friend->post(self::NODES.'&action=insert', ['name' => ' Probe-X1 ', '_csrf_token' => $friend->tokenFrom(self::NODES.'&action=insert', 'node-insert')]);
        self::assertSame(200, $response->getStatusCode());

        $node = $this->db()->fetchAssociative("SELECT * FROM nodes WHERE name = 'Probe-X1'");
        self::assertFalse($node['map']);
        self::assertNull($node['gps_lat_deg']);
        self::assertNull($node['gps_lon_sec']);
        self::assertSame([8, 8], $this->row($node['id']));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidNodes(): iterable
    {
        yield 'no name' => [['name' => ''], 'name'];
        yield 'leading hyphen' => [['name' => '-probe'], 'name'];
        yield 'trailing hyphen' => [['name' => 'probe-'], 'name'];
        yield 'underscore' => [['name' => 'pro_be'], 'name'];
        yield 'dot' => [['name' => 'pro.be'], 'name'];
        yield '26 characters' => [['name' => str_repeat('a', 26)], 'name'];
        yield 'taken, ignoring case' => [['name' => 'ALPHA'], 'name'];
        yield 'latitude degrees' => [['gps_lat_deg' => '91'], 'gps_lat_deg'];
        yield 'latitude minutes' => [['gps_lat_min' => '60'], 'gps_lat_min'];
        yield 'latitude seconds' => [['gps_lat_sec' => '59.9999'], 'gps_lat_sec'];
        yield 'longitude degrees' => [['gps_lon_deg' => '180.5'], 'gps_lon_deg'];
        yield 'negative' => [['gps_lon_min' => '-1'], 'gps_lon_min'];
        yield 'not a number' => [['gps_lon_sec' => '1e1'], 'gps_lon_sec'];
    }

    /**
     * PAR-NODE-06
     *
     * @param array<string, string> $fields
     */
    #[DataProvider('invalidNodes')]
    public function testInvalidNodeIsRejectedWithTheFormAndNothingIsWritten(array $fields, string $field): void
    {
        $before = $this->nodes();
        $response = $this->createNode($this->loggedIn('testfriend'), $fields);

        self::assertSame(422, $response->getStatusCode());
        $html = $response->getContent(false);
        self::assertStringContainsString('data-status="rejected"', $html);
        self::assertMatchesRegularExpression('/name="'.$field.'" value="[^"]*"[^>]*aria-invalid="true"/', $html);
        self::assertStringContainsString('name="gps_lat_sec" value="'.($fields['gps_lat_sec'] ?? '30,5').'"', $html);
        self::assertSame($before, $this->nodes());
    }

    /** PAR-NODE-07 */
    public function testOwnerAndTechContactChangeNameLocationAndMap(): void
    {
        $owner = $this->loggedIn('testuser');
        $form = $owner->get(self::url('update', 3))->getContent();
        self::assertStringContainsString('name="name" value="gamma"', $form);
        self::assertStringContainsString('name="gps_lat_min" value="14"', $form);
        self::assertStringNotContainsString('name="map" value="on" checked', $form);

        $response = $owner->post(self::url('update', 3), self::nodeForm(['name' => 'gamma2', '_csrf_token' => Browser::token($form, 'node-update')]));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Der Node &quot;gamma2&quot; wurde geändert.', $response->getContent());
        $node = $this->db()->fetchAssociative('SELECT * FROM nodes WHERE id = 3');
        self::assertSame(['gamma2', 30.5, true], [$node['name'], (float) $node['gps_lat_sec'], $node['map']]);
        // Changing a node never changes who owns or looks after it.
        self::assertSame([3, 4], $this->row(3));

        $techC = $this->loggedIn('testowner2');
        $response = $techC->post(self::url('update', 3), ['name' => 'gamma2', 'gps_lat_deg' => '47', '_csrf_token' => $techC->tokenFrom(self::url('update', 3), 'node-update')]);
        self::assertSame(200, $response->getStatusCode());
        $node = $this->db()->fetchAssociative('SELECT * FROM nodes WHERE id = 3');
        self::assertSame([47.0, null, false], [(float) $node['gps_lat_deg'], $node['gps_lat_sec'], $node['map']]);
        self::assertSame([3, 4], $this->row(3));
    }

    /** PAR-NODE-07 */
    public function testChangeIsRejectedForInvalidOrTakenNames(): void
    {
        $owner = $this->loggedIn('testuser');
        $before = $this->nodes();
        $token = $owner->tokenFrom(self::url('update', 3), 'node-update');

        foreach (['alpha', 'Beta', 'gam ma'] as $name) {
            $response = $owner->post(self::url('update', 3), self::nodeForm(['name' => $name, '_csrf_token' => $token]));
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringContainsString('name="name" value="'.$name.'"', $response->getContent(false));
        }
        self::assertSame($before, $this->nodes());
        // Its own name stays allowed.
        self::assertSame(200, $owner->post(self::url('update', 3), self::nodeForm(['name' => 'GAMMA', '_csrf_token' => $token]))->getStatusCode());
    }

    /** PAR-NODE-07-neg: no admin privilege; a valid token of one's own node does not open a foreign one. */
    public function testChangeIsRefusedForUninvolvedMembersRecipientsAndAdmins(): void
    {
        $before = $this->nodes();
        foreach (['testfriend', 'testadmin', 'testplain', 'testtech'] as $nickname) {
            $browser = $this->loggedIn($nickname);
            self::assertSame(403, $browser->get(self::url('update', 1))->getStatusCode(), $nickname);
            $this->createNode($browser, ['name' => 'own-'.$nickname]);
            $token = $browser->tokenFrom(self::url('update', $this->nodeId('own-'.$nickname)), 'node-update');
            self::assertSame(403, $browser->post(self::url('update', 1), self::nodeForm(['name' => 'stolen', '_csrf_token' => $token]))->getStatusCode(), $nickname);
        }
        self::assertSame($before, array_values(array_filter($this->nodes(), static fn (array $row) => $row['id'] <= 4)));
    }

    /** PAR-NODE-08, PAR-NODE-16 */
    public function testDeleteAsksFirstAndThenRemovesTheNodeWithItsProposal(): void
    {
        $owner = $this->loggedIn('testtech');
        $this->propose($owner, 'proposeTechC', 4, ['id_tech_c' => 'testfriend']);
        self::assertSame([5, 8], $this->row(-4));
        $before = $this->nodes();

        $confirm = $owner->get(self::url('delete', 4));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Soll der Node delta (ID 4) endgültig gelöscht werden?', $confirm->getContent());
        self::assertSame($before, $this->nodes(), 'GET must not delete');

        $response = $this->confirm($owner, 'delete', 4);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Der Node &quot;delta&quot; wurde gelöscht.', $response->getContent());
        self::assertNull($this->row(4));
        self::assertNull($this->row(-4));
        self::assertCount(5, $this->nodes());
        // Neither the overview nor the home page know the node any more.
        self::assertSame('', self::nodeRow($owner->get(self::NODES)->getContent(), 'owned', 4));
        self::assertStringNotContainsString('Eigene Nodes', $owner->get('/')->getContent());
        self::assertSame('', self::section($this->loggedIn('testfriend')->get(self::NODES)->getContent(), 'incoming-tech-c'));
    }

    /** PAR-NODE-08: the Tech-C manages the node like the owner. */
    public function testTechContactMayDeleteAnEmptyNode(): void
    {
        $this->db()->executeStatement('UPDATE nodes SET id_tech_c = 8 WHERE id = 4');

        $response = $this->confirm($this->loggedIn('testfriend'), 'delete', 4);
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->row(4));
    }

    /** PAR-NODE-09 */
    public function testDeleteIsRefusedWhileDevicesOrAddressesHangOnTheNode(): void
    {
        $owner = $this->loggedIn('testuser');
        $before = $this->nodes();

        $confirm = $owner->get(self::url('delete', 1));
        self::assertSame(409, $confirm->getStatusCode());
        self::assertStringContainsString('Devices vorhanden: Löschen nicht möglich', $confirm->getContent(false));

        // A direct request with a valid token (taken from an own, empty node) is refused as well.
        $this->createNode($owner, ['name' => 'empty']);
        $before[] = $this->db()->fetchAssociative("SELECT * FROM nodes WHERE name = 'empty'");
        $fields = $this->confirmation($owner, 'delete', $this->nodeId('empty'));
        foreach ([1, 3] as $id) {
            $response = $owner->post(self::url('delete', $id), $fields);
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('data-status="rejected"', $response->getContent(false));
        }
        self::assertSame($before, $this->nodes());
        self::assertSame(4, (int) $this->db()->fetchOne('SELECT count(*) FROM devices'));
    }

    /** PAR-NODE-08-neg */
    public function testDeleteIsRefusedForUninvolvedMembersRecipientsAndAdmins(): void
    {
        foreach (['testfriend', 'testadmin', 'testuser'] as $nickname) {
            $browser = $this->loggedIn($nickname);
            self::assertSame(403, $browser->get(self::url('delete', 4))->getStatusCode(), $nickname);
            $this->createNode($browser, ['name' => 'own-'.$nickname]);
            $fields = $this->confirmation($browser, 'delete', $this->nodeId('own-'.$nickname));
            self::assertSame(403, $browser->post(self::url('delete', 4), $fields)->getStatusCode(), $nickname);
        }
        self::assertSame([5, 5], $this->row(4));
    }

    /** PAR-NODE-14, PAR-NODE-16: the ownership survives. */
    public function testTechContactResignsAndTheOwnerBecomesTechContactAgain(): void
    {
        $techC = $this->loggedIn('testowner2');
        $confirm = $techC->get(self::url('resign', 3));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Soll Olga Owner nicht mehr Tech-Contact des Nodes gamma sein?', $confirm->getContent());
        self::assertSame([3, 4], $this->row(3), 'GET must not resign');

        $response = $this->confirm($techC, 'resign', 3);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([3, 3], $this->row(3));
        self::assertSame('', self::section($techC->get(self::NODES)->getContent(), 'tech-c'));
        self::assertSame(403, $techC->get(self::url('update', 3))->getStatusCode());
    }

    /** PAR-NODE-01, PAR-NODE-14 */
    public function testOwnerRemovesTheTechContact(): void
    {
        $response = $this->confirm($this->loggedIn('testowner2'), 'resign', 2);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([4, 4], $this->row(2));
        // The pending handover of the node is a different matter and stays.
        self::assertSame([7, 4], $this->row(-2));
    }

    /** PAR-NODE-14-neg */
    public function testResignNeedsASeparateTechContactAndARoleOnTheNode(): void
    {
        $before = $this->nodes();
        $owner = $this->loggedIn('testuser');
        self::assertSame(409, $owner->get(self::url('resign', 1))->getStatusCode());
        $fields = $this->confirmation($owner, 'resign', 3);
        self::assertSame(409, $owner->post(self::url('resign', 1), $fields)->getStatusCode());
        // testuser has no role on beta; testplain is merely invited to alpha.
        self::assertSame(403, $owner->post(self::url('resign', 2), $fields)->getStatusCode());
        self::assertSame(403, $this->loggedIn('testplain')->get(self::url('resign', 1))->getStatusCode());
        self::assertSame(403, $this->loggedIn('testadmin')->get(self::url('resign', 3))->getStatusCode());
        self::assertSame($before, $this->nodes());
    }

    /** Negative ids are proposal rows, never nodes, even for the members named in them. */
    public function testProposalRowsAndUnknownIdsAreNotAddressableAsNodes(): void
    {
        $owner = $this->loggedIn('testuser');
        $before = $this->nodes();
        $update = $owner->tokenFrom(self::url('update', 1), 'node-update');
        $resign = $this->confirmation($owner, 'resign', 3);

        foreach (['-1', '0', '424242', 'abc', '1.5', ''] as $id) {
            foreach (['update', 'delete', 'resign', 'withdraw', 'accept', 'decline', 'proposeOwner', 'proposeTechC'] as $action) {
                self::assertSame(404, $owner->get(self::url($action, $id))->getStatusCode(), $action.' '.$id);
            }
            self::assertSame(404, $owner->post(self::url('update', $id), self::nodeForm(['_csrf_token' => $update]))->getStatusCode(), $id);
            self::assertSame(404, $owner->post(self::url('resign', $id), $resign)->getStatusCode(), $id);
        }
        self::assertSame(404, $owner->get(self::NODES.'&action=update')->getStatusCode());
        self::assertSame(404, $owner->get(self::NODES.'&action=bogus&id=1')->getStatusCode());
        self::assertSame($before, $this->nodes());
    }

    /** A-1: writes are POST requests with a CSRF token. */
    public function testWritesNeedPostAndAValidToken(): void
    {
        $owner = $this->loggedIn('testtech');
        $before = $this->nodes();

        foreach ([self::NODES.'&action=insert', self::url('update', 4), self::url('delete', 4), self::url('resign', 2), self::url('proposeOwner', 4), self::url('proposeTechC', 4)] as $url) {
            self::assertSame(403, $owner->post($url, self::nodeForm(['id_tech_c' => 'testfriend']))->getStatusCode(), $url);
            self::assertSame(403, $owner->post($url, self::nodeForm(['id_tech_c' => 'testfriend', '_csrf_token' => 'wrong']))->getStatusCode(), $url);
            self::assertSame(405, $owner->request('PUT', $url)->getStatusCode(), $url);
        }
        // A token is bound to its action.
        $token = $owner->tokenFrom(self::url('update', 4), 'node-update');
        self::assertSame(403, $owner->post(self::url('delete', 4), ['_csrf_token' => $token])->getStatusCode());
        self::assertSame($before, $this->nodes());
    }
}
