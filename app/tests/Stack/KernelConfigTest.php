<?php

namespace App\Tests\Stack;

final class KernelConfigTest extends StackTestCase
{
    public function testSessionCookieIsConfiguredFromEnvironment(): void
    {
        // The test environment mocks session storage; check what production would use.
        self::bootKernel(['environment' => 'prod']);
        $options = self::$kernel->getContainer()->getParameter('session.storage.options');

        self::assertSame('/wien/', $options['cookie_path']);
        self::assertSame('strict', $options['cookie_samesite']);
        self::assertFalse($options['cookie_secure']);
        self::assertSame('/var/lib/redeemer/sessions', self::$kernel->getContainer()->getParameter('session.save_path'));
    }

    public function testSecretComesFromEnvironment(): void
    {
        self::bootKernel();

        self::assertSame(getenv('APP_SECRET'), self::getContainer()->getParameter('kernel.secret'));
    }
}
