<?php

namespace App\Member;

/** The LDAP account of a member, found by its uidNumber (= member id). Every method throws BackendFailure. */
interface MemberDirectory
{
    public function nicknameExists(string $nickname): bool;

    public function exists(int $memberId): bool;

    /** Creates the account with uidNumber/gidNumber -1 until the database assigns the id. */
    public function add(string $nickname, Profile $profile, #[\SensitiveParameter] string $password): void;

    public function assignId(string $nickname, int $memberId): void;

    /** @param array<string, string> $attributes givenName, sn, mail, userPassword */
    public function update(int $memberId, #[\SensitiveParameter] array $attributes): void;

    public function remove(int $memberId): void;
}
