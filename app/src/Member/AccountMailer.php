<?php

namespace App\Member;

/** Mails with credentials. Throws BackendFailure; never put the password into the message. */
interface AccountMailer
{
    public function sendWelcome(string $to, string $nickname, #[\SensitiveParameter] string $password): void;

    public function sendReset(string $to, string $nickname, #[\SensitiveParameter] string $password): void;
}
