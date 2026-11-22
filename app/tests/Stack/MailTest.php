<?php

namespace App\Tests\Stack;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpClient\HttpClient;

final class MailTest extends StackTestCase
{
    public function testMailerDeliversToMailpitOnly(): void
    {
        self::assertStringStartsWith('smtp://mail:1025', (string) getenv('MAILER_DSN'));

        $mailpit = HttpClient::createForBaseUri((string) getenv('MAILPIT_URL'));
        $mailpit->request('DELETE', '/api/v1/messages');

        self::getContainer()->get(MailerInterface::class)->send(
            (new Email())->from((string) getenv('MAIL_FROM'))->to('testuser@example.test')->subject('Stack probe')->text('probe'),
        );

        $messages = $mailpit->request('GET', '/api/v1/messages')->toArray();
        self::assertSame(1, $messages['total']);
        self::assertSame('Stack probe', $messages['messages'][0]['Subject']);
        self::assertSame('testuser@example.test', $messages['messages'][0]['To'][0]['Address']);
    }
}
