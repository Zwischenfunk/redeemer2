<?php

namespace App\Member;

/** The member search as stored in the session. */
final readonly class SearchFilter
{
    /**
     * @param list<int> $inGroups
     * @param list<int> $offGroups
     */
    public function __construct(
        public string $term = '',
        public array $inGroups = [],
        public array $offGroups = [],
        public bool $limited = true,
    ) {
    }

    /** @return array{term: string, inGroups: list<int>, offGroups: list<int>, limited: bool} */
    public function toArray(): array
    {
        return ['term' => $this->term, 'inGroups' => $this->inGroups, 'offGroups' => $this->offGroups, 'limited' => $this->limited];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['term'] ?? null) ? $data['term'] : '',
            array_values(array_filter((array) ($data['inGroups'] ?? []), is_int(...))),
            array_values(array_filter((array) ($data['offGroups'] ?? []), is_int(...))),
            (bool) ($data['limited'] ?? true),
        );
    }

    /** @return list<int>|null null if the text is not a comma separated list of positive integers */
    public static function parseGroups(string $text): ?array
    {
        $text = trim($text);
        if ('' === $text) {
            return [];
        }
        $ids = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part);
            if (1 !== preg_match('/^[0-9]{1,9}$/', $part)) {
                return null;
            }
            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    }

    /** Whether the search is specific enough to run (legacy: 3 characters without %, a numeric id, or a group filter). */
    public function isSpecific(): bool
    {
        return strlen(str_replace('%', '', $this->term)) >= 3 || ctype_digit($this->term)
            || [] !== $this->inGroups || [] !== $this->offGroups;
    }
}
