<?php

namespace App\Member;

/** What an actor may change on one member profile. */
final readonly class Permission
{
    /** @param list<string> $fields */
    public function __construct(
        public array $fields,
        public bool $password,
        public bool $contactOnly = false,
    ) {
    }

    public function names(): bool
    {
        return in_array('firstname', $this->fields, true);
    }
}
