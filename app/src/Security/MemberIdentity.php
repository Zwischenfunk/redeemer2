<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class MemberIdentity implements UserInterface, EquatableInterface
{
    public string $effectiveNickname;

    /** @param list<int> $originalRoleIds */
    public function __construct(
        public int $originalId,
        public int $effectiveId,
        public string $nickname,
        public array $originalRoleIds,
        ?string $effectiveNickname = null,
    ) {
        $this->effectiveNickname = $effectiveNickname ?? $nickname;
    }

    public function actingAs(int $memberId, string $nickname): self
    {
        return new self($this->originalId, $memberId, $this->nickname, $this->originalRoleIds, $nickname);
    }

    public function isImpersonating(): bool
    {
        return $this->effectiveId !== $this->originalId;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->originalId;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return match (true) {
            in_array(1, $this->originalRoleIds, true) => ['ROLE_MEMBER', 'ROLE_ADMIN'],
            in_array(2, $this->originalRoleIds, true) => ['ROLE_MEMBER', 'ROLE_SUPERUSER'],
            default => ['ROLE_MEMBER'],
        };
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $this->originalId === $user->originalId
            && $this->nickname === $user->nickname && $this->getRoles() === $user->getRoles();
    }

    public function eraseCredentials(): void
    {
    }
}
