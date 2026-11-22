<?php

namespace App\Tests\Stack;

use PHPUnit\Framework\TestCase;

/** The test network has no gateway: neither real MX hosts nor real DNS are reachable. */
final class NoEgressTest extends TestCase
{
    public function testPublicSmtpAndLdapPortsAreUnreachable(): void
    {
        foreach (['203.0.113.25:25', '203.0.113.25:587', '203.0.113.25:389'] as $target) {
            $socket = @stream_socket_client('tcp://'.$target, $errno, $error, 3);
            self::assertFalse($socket, $target.' must not be reachable');
        }
    }

    public function testPublicDnsDoesNotResolve(): void
    {
        self::assertFalse(@dns_get_record('gmail.com', DNS_MX));
        self::assertSame('no-such-host.example.net', gethostbyname('no-such-host.example.net'));
    }

    public function testRealMailHostsAreNotConfigured(): void
    {
        self::assertSame('mail', parse_url((string) getenv('MAILER_DSN'), PHP_URL_HOST));
        self::assertSame('ldap', parse_url((string) getenv('LDAP_URI'), PHP_URL_HOST));
    }
}
