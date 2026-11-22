<?php

namespace App\Member;

final class MemberPolicy
{
    private const CONTACT = ['telephone', 'mobilephone', 'email'];
    private const COMMON = ['street', 'housenumber', 'zip', 'town', 'telephone', 'mobilephone', 'fax', 'email', 'homepage'];

    /** Null means no access at all. */
    public function forExisting(Actor $actor, int $targetId): ?Permission
    {
        $own = $actor->effectiveId === $targetId;
        $password = $own;

        return match (true) {
            $actor->isAdmin() => new Permission(['firstname', 'lastname', ...self::COMMON], $password),
            $own => new Permission(self::COMMON, $password),
            $actor->isSuperuser() => new Permission(self::CONTACT, false, true),
            default => null,
        };
    }

    public function mayCreate(Actor $actor): bool
    {
        return $actor->isAdmin() || $actor->isSuperuser();
    }

    public function maySearch(Actor $actor): bool
    {
        return $actor->isAdmin() || $actor->isSuperuser();
    }

    public function mayDelete(Actor $actor): bool
    {
        return $actor->isAdmin();
    }

    public function mayTransform(Actor $actor): bool
    {
        return $actor->isAdmin();
    }

    public function insertPermission(): Permission
    {
        return new Permission(['nickname', 'firstname', 'lastname', ...self::COMMON], true);
    }
}
