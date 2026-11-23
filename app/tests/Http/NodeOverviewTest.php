<?php

namespace App\Tests\Http;

/** PAR-NODE-01 to 04: what owner, Tech-C, recipients and uninvolved members see under ?section=nodes. */
final class NodeOverviewTest extends NodeTestCase
{
    /** PAR-NODE-01 */
    public function testOwnerSeesOwnNodesWithLocationTechContactDevicesAndIds(): void
    {
        $response = $this->loggedIn('testuser')->get(self::NODES);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        $html = $response->getContent();
        self::assertStringContainsString('href="/wien/?section=nodes&amp;action=insert"', $html);

        $alpha = self::nodeRow($html, 'owned', 1);
        self::assertStringContainsString('href="/wien/?section=nodes&amp;action=update&amp;id=1" data-action="update">alpha</a>', $alpha);
        // 48° 12' 30" and 16° 22' 10" as decimal degrees
        self::assertStringContainsString('48.2083<br>16.3694', $alpha);
        self::assertStringContainsString('disabled checked', $alpha);
        self::assertStringContainsString('href="/wien/?section=devices&amp;id_nodes=1"', $alpha);
        self::assertStringContainsString('title="2 (v4) + 4 (v6)"', $alpha);
        self::assertStringContainsString('6 Devices', $alpha);
        self::assertStringContainsString('1<br>0x0001', $alpha);
        // Devices and IPv6 addresses hang on it: no delete link.
        self::assertStringContainsString('Devices vorhanden: Löschen nicht möglich', $alpha);
        self::assertStringNotContainsString('data-action="delete"', $alpha);
        // The owner is Tech-C: nobody to remove. The pending invitation of testplain can be withdrawn.
        self::assertStringNotContainsString('data-action="resign"', $alpha);
        self::assertStringContainsString('Einladung an <a href="mailto:testplain@example.test">Paula Plain</a>', $alpha);
        self::assertStringContainsString('action=withdraw&amp;id=1', $alpha);
        self::assertStringContainsString('action=proposeTechC&amp;id=1', $alpha);
        self::assertStringContainsString('action=proposeOwner&amp;id=1', $alpha);

        $gamma = self::nodeRow($html, 'owned', 3);
        self::assertStringContainsString('<a href="mailto:testowner2@example.test">Olga Owner</a>', $gamma);
        self::assertStringContainsString('action=resign&amp;id=3', $gamma);
        self::assertStringNotContainsString('disabled checked', $gamma);
        self::assertStringContainsString('1 Device<', $gamma);
        self::assertStringContainsString('3<br>0x0003', $gamma);

        // Proposal rows are not nodes, foreign nodes are not listed, and there are no other sections.
        self::assertStringNotContainsString('data-node-id="-1"', $html);
        self::assertStringNotContainsString('beta', $html);
        self::assertSame(1, substr_count($html, '<table'));
    }

    /** PAR-NODE-01 */
    public function testEmptyNodeCanBeDeletedAndOnlineStateFollowsLastSeen(): void
    {
        $tech = $this->loggedIn('testtech');
        $delta = self::nodeRow($tech->get(self::NODES)->getContent(), 'owned', 4);
        self::assertStringContainsString('action=delete&amp;id=4', $delta);
        self::assertStringContainsString('-<br>-', $delta);
        self::assertStringContainsString('0 Devices', $delta);
        self::assertStringContainsString('data-online="no"', $delta);

        // Seconds 59, never the midnight placeholder 00:00:00.
        $this->db()->executeStatement("UPDATE nodes SET last_seen = date_trunc('minute', now()) - interval '61 seconds' WHERE id = 4");
        self::assertStringContainsString('data-online="yes"', self::nodeRow($tech->get(self::NODES)->getContent(), 'owned', 4));
        $this->db()->executeStatement("UPDATE nodes SET last_seen = now() - interval '5 hours' WHERE id = 4");
        self::assertStringContainsString('data-online="no"', self::nodeRow($tech->get(self::NODES)->getContent(), 'owned', 4));
    }

    /** PAR-NODE-01, PAR-NODE-02 */
    public function testTechContactSeesForeignNodesWithOwnerAndResign(): void
    {
        $html = $this->loggedIn('testowner2')->get(self::NODES)->getContent();

        $beta = self::nodeRow($html, 'owned', 2);
        self::assertStringContainsString('<a href="mailto:testtech@example.test">Tom Tech</a>', $beta);
        self::assertStringContainsString('action=resign&amp;id=2', $beta);
        self::assertStringContainsString('Weitergabe an <a href="mailto:testhonor@example.test">Hans Honor</a> wartet auf Bestätigung', $beta);
        self::assertStringContainsString('action=withdraw&amp;id=2', $beta);
        self::assertStringNotContainsString('action=proposeOwner', $beta);

        $gamma = self::nodeRow($html, 'tech-c', 3);
        self::assertStringContainsString('action=update&amp;id=3', $gamma);
        self::assertStringContainsString('<a href="mailto:testuser@example.test">Uli User</a>', $gamma);
        self::assertStringContainsString('48.2333<br>16.4000', $gamma);
        self::assertStringContainsString('href="/wien/?section=devices&amp;id_nodes=3"', $gamma);
        self::assertStringContainsString('action=resign&amp;id=3" data-action="resign">Verzichten', $gamma);
        // Deleting and handing over is offered to the owner only.
        self::assertStringNotContainsString('action=delete', $gamma);
        self::assertStringNotContainsString('action=propose', $gamma);

        self::assertSame('', self::section($this->loggedIn('testuser')->get(self::NODES)->getContent(), 'tech-c'));
    }

    /** PAR-NODE-03 */
    public function testInvitedTechContactSeesTheProposalWithAcceptAndDecline(): void
    {
        $html = $this->loggedIn('testplain')->get(self::NODES)->getContent();
        self::assertStringContainsString('Du hast noch keine Knoten angelegt.', $html);

        $alpha = self::nodeRow($html, 'incoming-tech-c', 1);
        self::assertStringContainsString('<strong>alpha</strong>', $alpha);
        self::assertStringContainsString('<a href="mailto:testuser@example.test">Uli User</a>', $alpha);
        self::assertStringContainsString('48.2083<br>16.3694', $alpha);
        self::assertStringContainsString('action=accept&amp;id=1', $alpha);
        self::assertStringContainsString('action=decline&amp;id=1', $alpha);
        // Until accepted the node is not the recipient's: no link to change it.
        self::assertStringNotContainsString('section=nodes&amp;action=update', $html);
        self::assertSame('', self::section($html, 'incoming-owner'));
    }

    /** PAR-NODE-04 */
    public function testProposedOwnerSeesTheProposalWithAcceptAndDecline(): void
    {
        $html = $this->loggedIn('testhonor')->get(self::NODES)->getContent();

        $beta = self::nodeRow($html, 'incoming-owner', 2);
        self::assertStringContainsString('<strong>beta</strong>', $beta);
        self::assertStringContainsString('Olga Owner bleibt Tech-Contact', $beta);
        self::assertStringContainsString('<a href="mailto:testowner2@example.test">Olga Owner</a>', $beta);
        self::assertStringContainsString('action=accept&amp;id=2', $beta);
        self::assertStringContainsString('action=decline&amp;id=2', $beta);
        self::assertSame('', self::section($html, 'incoming-tech-c'));
    }

    /** PAR-NODE-01-neg */
    public function testOnlyTheOwnListIsShown(): void
    {
        $user = $this->loggedIn('testuser');
        self::assertSame(200, $user->get(self::NODES.'&id_members=3')->getStatusCode());
        self::assertSame(403, $user->get(self::NODES.'&id_members=4')->getStatusCode());
        self::assertSame(403, $user->get(self::NODES.'&id_members=abc')->getStatusCode());
        self::assertSame(403, $this->loggedIn('testadmin')->get(self::NODES.'&id_members=3')->getStatusCode());
        self::assertSame(405, $user->post(self::NODES)->getStatusCode());

        $html = $this->loggedIn('testfriend')->get(self::NODES)->getContent();
        self::assertStringContainsString('Du hast noch keine Knoten angelegt.', $html);
        self::assertStringNotContainsString('<table', $html);

        $anonymous = (new Browser())->get(self::NODES)->getContent();
        self::assertStringContainsString('name="password"', $anonymous);
        self::assertStringNotContainsString('alpha', $anonymous);
    }

    /** A-3: object rights follow the member an admin acts as; being admin adds none. */
    public function testImpersonatingAdminSeesTheNodesOfThatMember(): void
    {
        $admin = $this->loggedIn('testadmin');
        self::assertStringContainsString('Du hast noch keine Knoten angelegt.', $admin->get(self::NODES)->getContent());

        $profile = '/?section=member&action=update&id=3';
        $switch = $admin->post($profile, ['transform' => 'go', '_csrf_token' => $admin->tokenFrom($profile, 'member-transform')]);
        self::assertSame(['/wien/?section=nodes'], $switch->getHeaders(false)['location']);

        $html = $admin->get(self::NODES)->getContent();
        self::assertStringContainsString('data-impersonating="testuser"', $html);
        self::assertNotSame('', self::nodeRow($html, 'owned', 1));
        self::assertSame(200, $admin->get(self::NODES.'&id_members=3')->getStatusCode());
        self::assertSame(403, $admin->get(self::NODES.'&id_members=1')->getStatusCode());
    }
}
