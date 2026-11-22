<?php

namespace App\Member;

/** Database writes. Every method throws BackendFailure. */
interface MemberStore
{
    public function insert(string $nickname, Profile $profile): int;

    /**
     * Saves the profile and the role selections in one transaction.
     *
     * @param list<array{ids: list<int>, selected: int}> $roleChanges selected 0 = none of the ids
     */
    public function update(int $memberId, Profile $profile, array $roleChanges): void;

    public function delete(int $memberId): void;
}
