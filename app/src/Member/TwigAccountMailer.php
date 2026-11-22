<?php

namespace App\Member;

use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Error\Error as TwigError;

final readonly class TwigAccountMailer implements AccountMailer
{
    public const SUBJECT = 'Frontend Zugangsdaten';

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private string $from,
        private string $fromName,
        private string $portalUrl,
    ) {
    }

    public function sendWelcome(string $to, string $nickname, #[\SensitiveParameter] string $password): void
    {
        $this->send('mail/welcome.txt.twig', $to, $nickname, $password);
    }

    public function sendReset(string $to, string $nickname, #[\SensitiveParameter] string $password): void
    {
        $this->send('mail/reset.txt.twig', $to, $nickname, $password);
    }

    private function send(string $template, string $to, string $nickname, #[\SensitiveParameter] string $password): void
    {
        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->from, $this->fromName))
                ->to(new Address($to))
                ->subject(self::SUBJECT)
                ->text($this->twig->render($template, ['nickname' => $nickname, 'password' => $password, 'portalUrl' => $this->portalUrl])));
        } catch (MailerException|TwigError|\InvalidArgumentException|\LogicException) {
            throw new BackendFailure('Die E-Mail konnte nicht versendet werden.');
        }
    }
}
