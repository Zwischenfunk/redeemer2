<?php

namespace App\Member;

/** Done: all effects happened. Rejected: validation/precondition failed, nothing changed. Failed: a backend failed before anything changed. Partial: some effects happened, some did not. */
final readonly class Outcome
{
    /**
     * @param list<string>          $messages
     * @param array<string, string> $errors
     */
    private function __construct(
        public Status $status,
        public array $messages = [],
        public array $errors = [],
        public ?int $memberId = null,
    ) {
    }

    /** @param list<string> $messages */
    public static function done(array $messages, ?int $memberId = null): self
    {
        return new self(Status::Done, $messages, memberId: $memberId);
    }

    /** @param array<string, string> $errors */
    public static function rejected(string $message, array $errors = []): self
    {
        return new self(Status::Rejected, [$message], $errors);
    }

    public static function failed(string $message): self
    {
        return new self(Status::Failed, [$message]);
    }

    /** @param list<string> $messages */
    public static function partial(array $messages, ?int $memberId = null): self
    {
        return new self(Status::Partial, $messages, memberId: $memberId);
    }

    public function isDone(): bool
    {
        return Status::Done === $this->status;
    }
}
