<?php

namespace App\Member;

final readonly class Profile
{
    public const FIELDS = ['nickname', 'firstname', 'lastname', 'street', 'housenumber', 'zip', 'town', 'telephone', 'mobilephone', 'fax', 'email', 'homepage'];

    public function __construct(
        public string $nickname,
        public string $firstname,
        public string $lastname,
        public string $street,
        public string $housenumber,
        public string $zip,
        public string $town,
        public string $telephone,
        public string $mobilephone,
        public string $fax,
        public string $email,
        public string $homepage,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(...array_map(static fn (string $field) => (string) ($values[$field] ?? ''), array_combine(self::FIELDS, self::FIELDS)));
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_combine(self::FIELDS, array_map(fn (string $field) => $this->$field, self::FIELDS));
    }

    /** @param array<string, string> $changes */
    public function with(array $changes): self
    {
        return self::fromArray($changes + $this->toArray());
    }
}
