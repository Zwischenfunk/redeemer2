<?php

namespace App\Member;

use App\Security\MemberIdentity;

/** Who acts: admin/superuser rights follow the original member, object access the effective one. */
final readonly class Actor
{
    /** @param list<int> $roleIds */
    public function __construct(
        public int $originalId,
        public int $effectiveId,
        public string $effectiveNickname,
        public array $roleIds,
    ) {
    }

    public static function from(MemberIdentity $identity): self
    {
        return new self($identity->originalId, $identity->effectiveId, $identity->effectiveNickname, $identity->originalRoleIds);
    }

    public function isAdmin(): bool
    {
        return in_array(1, $this->roleIds, true);
    }

    public function isSuperuser(): bool
    {
        return !$this->isAdmin() && in_array(2, $this->roleIds, true);
    }

    public function isImpersonating(): bool
    {
        return $this->effectiveId !== $this->originalId;
    }
}
