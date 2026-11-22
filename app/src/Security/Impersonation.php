<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/** The session-held "act as member" target of an admin; it never changes the admin's own rights. */
final readonly class Impersonation
{
    private const KEY = 'impersonated_member_id';

    public function __construct(private RequestStack $requests)
    {
    }

    public function target(): ?int
    {
        $session = $this->requests->getCurrentRequest()?->hasSession() ? $this->requests->getSession() : null;
        $id = $session?->get(self::KEY);

        return is_int($id) ? $id : null;
    }

    public function start(int $memberId): void
    {
        $this->requests->getSession()->set(self::KEY, $memberId);
    }

    public function stop(): void
    {
        $this->requests->getSession()->remove(self::KEY);
    }
}
