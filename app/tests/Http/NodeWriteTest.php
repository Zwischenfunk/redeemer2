<?php

namespace App\Tests\Http;

use App\Infrastructure\DatabaseConnectionFactory;
use App\Member\Actor;
use App\Node\Handover;
use App\Node\NodeAccess;
use App\Node\NodeQueries;
use App\Node\Nodes;
use App\Node\Proposal;
use App\Node\ProposalKind;
use App\Node\Rejected;
use App\Tests\Stack\StackTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The write convention handed to the devices module (#9): every write locks its node before it checks and
 * writes, all in one transaction. Runs on the real database; nothing is committed.
 */
final class NodeWriteTest extends StackTestCase
{
    private static function actor(int $memberId): Actor
    {
        return new Actor($memberId, $memberId, 'member'.$memberId, []);
    }

    /** @return iterable<string, array{\Closure(Nodes, Handover): mixed}> every write of the module, called by a member who may do it */
    public static function writes(): iterable
    {
        yield 'update' => [static fn (Nodes $nodes) => $nodes->update(self::actor(3), 1, ['name' => 'alpha'])];
        yield 'delete' => [static fn (Nodes $nodes) => $nodes->delete(self::actor(3), 1)];
        yield 'resign' => [static fn (Nodes $nodes) => $nodes->resign(self::actor(3), 1)];
        yield 'propose' => [static fn (Nodes $nodes, Handover $handover) => $handover->propose(self::actor(3), 1, ProposalKind::Owner, ['id_tech_c' => '8'])];
        yield 'withdraw' => [static fn (Nodes $nodes, Handover $handover) => $handover->withdraw(self::actor(3), 1, 'techc-6-6')];
        yield 'accept' => [static fn (Nodes $nodes, Handover $handover) => $handover->accept(self::actor(6), 1, 'techc-6-6')];
        yield 'decline' => [static fn (Nodes $nodes, Handover $handover) => $handover->decline(self::actor(6), 1, 'techc-6-6')];
    }

    /** @param \Closure(Nodes, Handover): mixed $write */
    #[DataProvider('writes')]
    public function testWriteWaitsForTheNodeLockOfAConcurrentWriter(\Closure $write): void
    {
        $db = $this->db();
        $before = $db->fetchAllAssociative('SELECT * FROM nodes ORDER BY id');
        $other = DatabaseConnectionFactory::create((string) getenv('DATABASE_URL'));
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM nodes WHERE id = 1 FOR UPDATE');
        $db->executeStatement("SET lock_timeout = '300ms'");

        try {
            $write(self::getContainer()->get(Nodes::class), self::getContainer()->get(Handover::class));
            self::fail('The write did not wait for the lock on its node.');
        } catch (DriverException $exception) {
            // 55P03 lock_not_available: the write was still waiting when the timeout struck.
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $db->executeStatement('RESET lock_timeout');
            $other->rollBack();
            $other->close();
        }
        self::assertFalse($db->isTransactionActive());
        self::assertSame($before, $db->fetchAllAssociative('SELECT * FROM nodes ORDER BY id'));
    }

    public function testLockingANodeNeedsATransaction(): void
    {
        $this->expectException(\LogicException::class);

        self::getContainer()->get(NodeAccess::class)->node(1, lock: true);
    }

    /** PAR-NODE-09: an address directly on the node blocks deleting it, like a device does. */
    public function testNodeWithOnlyAnIpv6AddressIsNotDeletable(): void
    {
        $this->rollingBack(function (Connection $db): void {
            $nodes = self::getContainer()->get(Nodes::class);
            self::assertSame(4, $nodes->deletable(self::actor(5), 4)->id);
            $db->fetchOne('SELECT insertip6(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', ['n', 4, '2a02:61:4::1', 'delta-6', true, true, true, null, null, null, null]);

            $this->expectException(Rejected::class);
            $nodes->delete(self::actor(5), 4);
        });
    }

    /** A proposal row that does not fit the node as it is now is nobody's proposal. */
    public function testInconsistentProposalRowIsIgnored(): void
    {
        $this->rollingBack(function (Connection $db): void {
            // Node 3 belongs to member 3: "hand over to 7, Tech-C afterwards 5" names neither the new nor the current owner.
            $db->executeStatement("INSERT INTO nodes (id, name, map, id_members, id_tech_c) VALUES (-3, '-3', false, 7, 5)");

            self::assertSame([2], array_map(static fn (Proposal $proposal) => $proposal->node->id, self::getContainer()->get(NodeQueries::class)->incoming(7)));
            self::assertNull(self::getContainer()->get(NodeQueries::class)->owned(3)[1]['proposal']);
            $this->expectException(Rejected::class);
            self::getContainer()->get(Handover::class)->accept(self::actor(7), 3, 'owner-7-5');
        });
    }
}
