<?php

namespace App\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PAR-NODE-10 to 13 and 15, A-2: the owner proposes or withdraws, the addressed member accepts or declines,
 * nobody else writes. Proposals are rows of the table nodes with the negated node id.
 */
final class NodeHandoverTest extends NodeTestCase
{
    /** PAR-NODE-10, PAR-NODE-04, PAR-NODE-12 */
    public function testOwnerHandsNodeOverAndStaysTechContact(): void
    {
        $owner = $this->loggedIn('testtech');
        $form = $owner->get(self::url('proposeOwner', 4))->getContent();
        self::assertStringContainsString('Neuer Besitzer für delta', $form);
        self::assertStringContainsString('name="recipient_type" required', $form);
        self::assertStringContainsString('name="stay_tech_c" value="on" checked', $form);

        $response = $this->propose($owner, 'proposeOwner', 4, ['recipient_type' => 'nickname', 'id_tech_c' => 'TestFriend', 'stay_tech_c' => 'on']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-status="done"', $response->getContent());
        self::assertStringContainsString('<a href="mailto:testfriend@example.test">Frida Friend</a>', $response->getContent());

        // Stored like the legacy frontend does: negated id, that id as name, new owner and future Tech-C.
        $proposal = $this->db()->fetchAssociative('SELECT * FROM nodes WHERE id = -4');
        self::assertSame(['-4', 8, 5, false, null, null], [$proposal['name'], (int) $proposal['id_members'], (int) $proposal['id_tech_c'], $proposal['map'], $proposal['gps_lat_deg'], $proposal['changed']]);
        self::assertNotNull($proposal['created']);
        self::assertSame([5, 5], $this->row(4), 'a proposal changes nothing yet');
        self::assertSame([], $this->messages(), 'the application sends no mail');
        self::assertStringContainsString('Weitergabe an <a href="mailto:testfriend@example.test">Frida Friend</a>', self::nodeRow($owner->get(self::NODES)->getContent(), 'owned', 4));

        $recipient = $this->loggedIn('testfriend');
        self::assertStringContainsString('Tom Tech bleibt Tech-Contact', self::nodeRow($recipient->get(self::NODES)->getContent(), 'incoming-owner', 4));
        self::assertStringContainsString('Du hast 1 offenen Vorschlag', $recipient->get('/')->getContent());
        $confirm = $recipient->get(self::url('accept', 4))->getContent();
        self::assertStringContainsString('Tom Tech schlägt dich für den Node delta vor.', $confirm);
        self::assertStringContainsString('Tom Tech bleibt Tech-Contact', $confirm);
        self::assertSame([5, 5], $this->row(4), 'GET must not accept');

        $response = $this->confirm($recipient, 'accept', 4);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Du bist jetzt Besitzer des Nodes &quot;delta&quot;.', $response->getContent());
        self::assertSame([8, 5], $this->row(4));
        self::assertNull($this->row(-4));

        self::assertNotSame('', self::nodeRow($recipient->get(self::NODES)->getContent(), 'owned', 4));
        self::assertStringNotContainsString('offenen Vorschlag', $recipient->get('/')->getContent());
        $former = $owner->get(self::NODES)->getContent();
        self::assertSame('', self::nodeRow($former, 'owned', 4));
        self::assertNotSame('', self::nodeRow($former, 'tech-c', 4));
        // The former owner is Tech-C now and may no longer hand the node over.
        self::assertSame(403, $owner->get(self::url('proposeOwner', 4))->getStatusCode());
    }

    /** PAR-NODE-10, PAR-NODE-12, PAR-HOME-04 */
    public function testOwnerHandsNodeOverCompletelyByMailAddress(): void
    {
        $response = $this->propose($this->loggedIn('testtech'), 'proposeOwner', 4, ['recipient_type' => 'email', 'id_tech_c' => 'TestFriend@Example.Test']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([8, 8], $this->row(-4));

        $recipient = $this->loggedIn('testfriend');
        // The legacy home page overlooked a handover without "stay Tech-C".
        self::assertStringContainsString('Du hast 1 offenen Vorschlag', $recipient->get('/')->getContent());
        self::assertStringContainsString('Übernahme als Besitzer und Tech-Contact', $recipient->get(self::url('accept', 4))->getContent());

        self::assertSame(200, $this->confirm($recipient, 'accept', 4)->getStatusCode());
        self::assertSame([8, 8], $this->row(4));
        self::assertNull($this->row(-4));
    }

    /** PAR-NODE-11, PAR-NODE-03, PAR-NODE-12 */
    public function testOwnerInvitesTechContactByIdWhoAccepts(): void
    {
        $owner = $this->loggedIn('testtech');
        self::assertStringContainsString('Tech-Contact für delta', $owner->get(self::url('proposeTechC', 4))->getContent());
        $response = $this->propose($owner, 'proposeTechC', 4, ['recipient_type' => 'id', 'id_tech_c' => '8', 'stay_tech_c' => 'on']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('mailto:testfriend@example.test', $response->getContent());
        self::assertSame([5, 8], $this->row(-4));

        $recipient = $this->loggedIn('testfriend');
        self::assertNotSame('', self::nodeRow($recipient->get(self::NODES)->getContent(), 'incoming-tech-c', 4));
        $response = $this->confirm($recipient, 'accept', 4);
        self::assertStringContainsString('Du bist jetzt Tech-Contact des Nodes &quot;delta&quot;.', $response->getContent());
        self::assertSame([5, 8], $this->row(4));
        self::assertNull($this->row(-4));
        self::assertSame(200, $recipient->get(self::url('update', 4))->getStatusCode());
    }

    /** PAR-NODE-12: seed proposal -2, the current Tech-C testtech gives way to the former owner. */
    public function testAcceptedOwnerProposalReplacesTheCurrentTechContact(): void
    {
        self::assertSame(200, $this->confirm($this->loggedIn('testhonor'), 'accept', 2)->getStatusCode());
        self::assertSame([7, 4], $this->row(2));
        self::assertNull($this->row(-2));
        self::assertSame(403, $this->loggedIn('testtech')->get(self::url('update', 2))->getStatusCode());
    }

    /** PAR-NODE-13, PAR-NODE-16 */
    public function testRecipientDeclines(): void
    {
        $recipient = $this->loggedIn('testplain');
        $before = $this->nodes();
        self::assertStringContainsString('Uli User schlägt dich für den Node alpha vor.', $recipient->get(self::url('decline', 1))->getContent());
        self::assertSame($before, $this->nodes(), 'GET must not decline');

        $response = $this->confirm($recipient, 'decline', 1);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('wurde abgelehnt', $response->getContent());
        self::assertNull($this->row(-1));
        self::assertSame([3, 3], $this->row(1));
        self::assertSame('', self::section($recipient->get(self::NODES)->getContent(), 'incoming-tech-c'));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function withdrawals(): iterable
    {
        yield 'new action' => ['testuser', 1, '&action=withdraw&id=1'];
        yield 'legacy link for a Tech-C invitation' => ['testuser', 1, '&action=resign&id=1&p=6'];
        yield 'legacy link for a handover' => ['testowner2', 2, '&action=decline&id=2&p=7'];
    }

    /** PAR-NODE-15, PAR-NODE-16, A-6 */
    #[DataProvider('withdrawals')]
    public function testOwnerWithdrawsTheProposal(string $nickname, int $id, string $query): void
    {
        $owner = $this->loggedIn($nickname);
        $before = $this->nodes();
        $confirm = $owner->get(self::NODES.$query);
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('zurückgezogen werden?', $confirm->getContent());
        self::assertSame($before, $this->nodes(), 'GET must not withdraw');

        $response = $this->confirm($owner, 'withdraw', $id);
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->row(-$id));
        self::assertSame(array_values(array_filter($before, static fn (array $row) => (int) $row['id'] !== -$id)), $this->nodes());
    }

    /** PAR-NODE-10-neg, PAR-NODE-11-neg, PAR-NODE-15-neg */
    public function testOnlyTheOwnerProposesOrWithdraws(): void
    {
        $before = $this->nodes();
        // testowner2 is Tech-C of gamma and owns beta, which gives valid tokens for both forms.
        $techC = $this->loggedIn('testowner2');
        $propose = $techC->tokenFrom(self::url('proposeTechC', 2), 'node-propose');
        $withdraw = $this->confirmation($techC, 'withdraw', 2);
        $fields = ['recipient_type' => 'nickname', 'id_tech_c' => 'testfriend', '_csrf_token' => $propose];
        foreach (['proposeOwner', 'proposeTechC'] as $action) {
            self::assertSame(403, $techC->get(self::url($action, 3))->getStatusCode());
            self::assertSame(403, $techC->post(self::url($action, 3), $fields)->getStatusCode());
            self::assertSame(403, $techC->post(self::url($action, 1), $fields)->getStatusCode());
        }
        self::assertSame(403, $techC->get(self::url('withdraw', 1))->getStatusCode());
        self::assertSame(403, $techC->post(self::url('withdraw', 1), ['proposal' => 'techc-6-6'] + $withdraw)->getStatusCode());

        // Neither the recipient nor the current Tech-C of a node takes the owner's proposal back.
        self::assertSame(403, $this->loggedIn('testplain')->get(self::url('withdraw', 1))->getStatusCode());
        self::assertSame(403, $this->loggedIn('testhonor')->get(self::NODES.'&action=decline&id=2&p=7')->getStatusCode());
        self::assertSame(403, $this->loggedIn('testtech')->get(self::url('withdraw', 2))->getStatusCode());
        self::assertSame(403, $this->loggedIn('testadmin')->get(self::url('proposeOwner', 1))->getStatusCode());
        self::assertSame($before, $this->nodes());
    }

    /** A-2, PAR-NODE-12-neg, PAR-NODE-13-neg */
    public function testOnlyTheAddressedMemberAcceptsOrDeclines(): void
    {
        $before = $this->nodes();
        // The owner and the Tech-C of the node decided in the legacy system, too. Not any more.
        foreach (['testowner2', 'testtech', 'testfriend', 'testadmin'] as $nickname) {
            $browser = $this->loggedIn($nickname);
            self::assertSame(403, $browser->get(self::url('accept', 2))->getStatusCode(), $nickname);
            self::assertSame(403, $browser->get(self::url('decline', 2))->getStatusCode(), $nickname);
        }
        self::assertSame(403, $this->loggedIn('testuser')->get(self::url('accept', 1))->getStatusCode());

        // A recipient holds valid tokens, but only for the proposal addressed to them.
        $other = $this->loggedIn('testplain');
        foreach (['accept', 'decline'] as $action) {
            $fields = $this->confirmation($other, $action, 1);
            self::assertSame(403, $other->post(self::url($action, 2), $fields)->getStatusCode());
            self::assertSame(403, $other->post(self::url($action, 2), ['proposal' => 'owner-7-4'] + $fields)->getStatusCode());
        }
        self::assertSame($before, $this->nodes());
    }

    /** A proposal that was replaced or taken back after the recipient saw it is not acted upon. */
    public function testOutdatedProposalIsNeitherAcceptedNorDeclined(): void
    {
        $recipient = $this->loggedIn('testplain');
        $accept = $this->confirmation($recipient, 'accept', 1);
        $decline = $this->confirmation($recipient, 'decline', 1);
        self::assertNotEmpty($accept['proposal']);
        self::assertSame($accept['proposal'], $decline['proposal']);

        // The owner now offers the whole node to the same member instead of the Tech-C role.
        $owner = $this->loggedIn('testuser');
        $replaced = $this->propose($owner, 'proposeOwner', 1, ['recipient_type' => 'nickname', 'id_tech_c' => 'testplain', 'stay_tech_c' => 'on']);
        self::assertStringContainsString('Der bisherige Vorschlag für diesen Node wurde dadurch ersetzt.', $replaced->getContent());
        $before = $this->nodes();
        foreach (['accept' => $accept, 'decline' => $decline] as $action => $fields) {
            $response = $recipient->post(self::url($action, 1), $fields);
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('Diesen Vorschlag gibt es nicht mehr', $response->getContent(false));
            // Without the version the member decided on nothing happens either.
            self::assertSame(409, $recipient->post(self::url($action, 1), ['_csrf_token' => $fields['_csrf_token']])->getStatusCode());
        }
        self::assertSame($before, $this->nodes());
        self::assertSame([6, 3], $this->row(-1));

        // Taken back: nothing left to decide, for anybody, on any node.
        self::assertSame(200, $this->confirm($owner, 'withdraw', 1)->getStatusCode());
        $before = $this->nodes();
        self::assertSame(409, $recipient->post(self::url('accept', 1), $accept)->getStatusCode());
        self::assertSame(409, $recipient->get(self::url('accept', 1))->getStatusCode());
        self::assertSame(409, $recipient->post(self::url('decline', 3), $decline)->getStatusCode());
        self::assertSame(409, $owner->get(self::url('withdraw', 1))->getStatusCode());
        self::assertSame($before, $this->nodes());
    }

    /** @return iterable<string, array{bool}> */
    public static function reissues(): iterable
    {
        yield 'replace directly' => [false];
        yield 'withdraw, then reissue' => [true];
    }

    #[DataProvider('reissues')]
    public function testOldConfirmationsCannotActOnAnIdenticalReissue(bool $withdrawFirst): void
    {
        $owner = $this->loggedIn('testtech');
        $recipient = $this->loggedIn('testfriend');
        $offer = ['recipient_type' => 'nickname', 'id_tech_c' => 'testfriend', 'stay_tech_c' => 'on'];
        self::assertSame(200, $this->propose($owner, 'proposeOwner', 4, $offer)->getStatusCode());

        $accept = $this->confirmation($recipient, 'accept', 4);
        $decline = $this->confirmation($recipient, 'decline', 4);
        $withdraw = $this->confirmation($owner, 'withdraw', 4);
        if ($withdrawFirst) {
            self::assertSame(200, $this->confirm($owner, 'withdraw', 4)->getStatusCode());
        }
        self::assertSame(200, $this->propose($owner, 'proposeOwner', 4, $offer)->getStatusCode());
        $before = $this->nodes();

        self::assertSame(409, $recipient->post(self::url('accept', 4), $accept)->getStatusCode());
        self::assertSame(409, $recipient->post(self::url('decline', 4), $decline)->getStatusCode());
        self::assertSame(409, $owner->post(self::url('withdraw', 4), $withdraw)->getStatusCode());
        self::assertSame($before, $this->nodes());

        self::assertSame(200, $this->confirm($recipient, 'accept', 4)->getStatusCode());
        self::assertSame([8, 5], $this->row(4));
        self::assertNull($this->row(-4));
    }

    /** The recipient changed: the first one is no longer asked, the second one decides. */
    public function testNewProposalReplacesTheOldOne(): void
    {
        $first = $this->loggedIn('testplain');
        $accept = $this->confirmation($first, 'accept', 1);

        $this->propose($this->loggedIn('testuser'), 'proposeTechC', 1, ['recipient_type' => 'email', 'id_tech_c' => 'testhonor@example.test']);
        self::assertSame([3, 7], $this->row(-1));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM nodes WHERE id = -1'));

        self::assertSame(403, $first->post(self::url('accept', 1), $accept)->getStatusCode());
        self::assertSame('', self::section($first->get(self::NODES)->getContent(), 'incoming-tech-c'));
        self::assertSame([3, 3], $this->row(1));

        self::assertSame(200, $this->confirm($this->loggedIn('testhonor'), 'accept', 1)->getStatusCode());
        self::assertSame([3, 7], $this->row(1));
    }

    public function testNumericRecipientIsResolvedOnlyByTheSelectedType(): void
    {
        $this->db()->executeStatement("UPDATE members SET nickname = '8' WHERE id = 6");
        $owner = $this->loggedIn('testtech');

        foreach (['nickname' => 6, 'id' => 8] as $type => $memberId) {
            $response = $this->propose($owner, 'proposeTechC', 4, ['recipient_type' => $type, 'id_tech_c' => '8']);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame([5, $memberId], $this->row(-4));
        }
    }

    /** @return iterable<string, array{string, int, string|null, string, string}> */
    public static function unusableRecipients(): iterable
    {
        yield 'no type' => ['proposeOwner', 1, null, '8', 'als Eingabetyp auswählen'];
        yield 'unknown type' => ['proposeOwner', 1, 'auto', '8', 'als Eingabetyp auswählen'];
        yield 'no value' => ['proposeOwner', 1, 'nickname', '', 'Bitte Member-ID'];
        yield 'unknown nickname' => ['proposeOwner', 1, 'nickname', 'nobody', 'Kein Benutzer mit diesem Nickname gefunden.'];
        yield 'unknown id' => ['proposeTechC', 1, 'id', '424242', 'Kein Benutzer mit dieser ID gefunden.'];
        yield 'negative id' => ['proposeTechC', 1, 'id', '-6', 'Bitte eine positive Member-ID angeben.'];
        yield 'id overflow' => ['proposeTechC', 1, 'id', '9223372036854775808', 'Bitte eine positive Member-ID angeben.'];
        yield 'unknown address' => ['proposeOwner', 1, 'email', 'nobody@example.test', 'Kein Benutzer mit dieser E-Mail-Adresse gefunden.'];
        yield 'address of two members' => ['proposeOwner', 1, 'email', 'shared@example.test', 'gehört zu mehreren Benutzern'];
        yield 'owner as owner' => ['proposeOwner', 1, 'nickname', 'testuser', 'bereits Besitzer'];
        yield 'owner as Tech-C' => ['proposeTechC', 3, 'id', '3', 'den bisherigen Tech-Contact entfernt'];
        yield 'Tech-C as Tech-C' => ['proposeTechC', 3, 'nickname', 'testowner2', 'bereits Tech-Contact'];
    }

    /** PAR-NODE-10, PAR-NODE-11 */
    #[DataProvider('unusableRecipients')]
    public function testProposalNeedsExactlyOneOtherMember(string $action, int $id, ?string $type, string $input, string $message): void
    {
        $this->db()->executeStatement("UPDATE members SET email = 'shared@example.test' WHERE id IN (7, 8)");
        $before = $this->nodes();

        $fields = ['id_tech_c' => $input] + (null === $type ? [] : ['recipient_type' => $type]);
        $response = $this->propose($this->loggedIn('testuser'), $action, $id, $fields);
        self::assertSame(422, $response->getStatusCode());
        $html = $response->getContent(false);
        self::assertStringContainsString($message, $html);
        self::assertStringContainsString('name="id_tech_c" value="'.$input.'"', $html);
        if (in_array($type, ['id', 'nickname', 'email'], true)) {
            self::assertStringContainsString('value="'.$type.'" selected', $html);
        }
        self::assertSame($before, $this->nodes());
    }
}
