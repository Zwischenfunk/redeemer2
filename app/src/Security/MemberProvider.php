<?php

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<MemberIdentity> */
final readonly class MemberProvider implements UserProviderInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function loadUserByIdentifier(string $identifier): MemberIdentity
    {
        $id = filter_var($identifier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $id) {
            throw new UserNotFoundException();
        }

        $nickname = $this->db->fetchOne('SELECT nickname FROM members WHERE id = ?', [$id]);
        if (!is_string($nickname)) {
            throw new UserNotFoundException();
        }

        $roleIds = $this->db->fetchFirstColumn('SELECT id_roles FROM members_roles WHERE id_members = ? ORDER BY id_roles', [$id]);

        return new MemberIdentity($id, $id, $nickname, array_map(intval(...), $roleIds));
    }

    public function refreshUser(UserInterface $user): MemberIdentity
    {
        if (!$user instanceof MemberIdentity) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return MemberIdentity::class === $class;
    }
}
