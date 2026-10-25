<?php

namespace App\Security\Voter;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class OrganizationVoter extends Voter
{
    public const EDIT = 'ORGANIZATION_EDIT';
    public const VIEW = 'ORGANIZATION_VIEW';

    public const MANAGE_MEMBERS = 'ORGANIZATION_MANAGE_MEMBERS';

    public function __construct(
        private readonly MembershipRepository $memberships
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Organization
            && in_array(
                $attribute,
                [self::VIEW, self::EDIT, self::MANAGE_MEMBERS],
                true
            );
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $membership = $this->memberships->findOneBy([
            'user' => $user,
            'organization' => $subject,
        ]);

        if ($membership === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::EDIT => in_array(
                $membership->getRole(),
                [MembershipRole::Owner, MembershipRole::Manager],
                true
            ),
            self::MANAGE_MEMBERS =>
                $membership->getRole() === MembershipRole::Owner,
            default => false,
        };
    }
}