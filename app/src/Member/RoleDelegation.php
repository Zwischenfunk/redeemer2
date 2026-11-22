<?php

namespace App\Member;

/**
 * USER_ROLES: groups of roles a member may set. Legacy tuple per key: [label, required role, "role ids"].
 * Required role 1 = admin, 2 = superuser, any other = the member itself, holding that role, not impersonating.
 */
final readonly class RoleDelegation
{
    /** @var array<string, array{label: string, required: int, ids: list<int>}> */
    private array $groups;

    /** @param array<string, array{0: string, 1: int, 2: string}> $definitions */
    public function __construct(array $definitions)
    {
        $groups = [];
        foreach ($definitions as $key => [$label, $required, $ids]) {
            $groups[(string) $key] = [
                'label' => (string) $label,
                'required' => (int) $required,
                'ids' => array_values(array_filter(array_map(intval(...), explode(',', (string) $ids)), static fn (int $id) => $id > 0)),
            ];
        }
        $this->groups = $groups;
    }

    /** @return array<string, array{label: string, ids: list<int>}> the groups the actor may set on the target */
    public function groupsFor(Actor $actor, int $targetId): array
    {
        $allowed = [];
        foreach ($this->groups as $key => $group) {
            $required = $group['required'];
            $permitted = match (true) {
                [] === $group['ids'] => false,
                1 === $required => $actor->isAdmin(),
                2 === $required => $actor->isSuperuser(),
                default => $actor->effectiveId === $targetId && !$actor->isImpersonating() && in_array($required, $actor->roleIds, true),
            };
            if ($permitted) {
                $allowed[$key] = ['label' => $group['label'], 'ids' => $group['ids']];
            }
        }

        return $allowed;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->groups));
    }
}
