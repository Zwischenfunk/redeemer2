<?php

namespace App\Node;

/** Validation or a precondition failed; nothing was written. The message is safe to show. */
final class Rejected extends \RuntimeException
{
    /** @param array<string, string> $errors by form field */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors by form field */
    public static function fields(array $errors): self
    {
        return new self('Bitte die markierten Felder korrigieren.', $errors);
    }
}
