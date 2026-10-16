<?php

namespace App\Tests\Unit;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use App\Security\Voter\OrganizationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class OrganizationVoterTest extends TestCase
{
    #[DataProvider('permissions')]
    public function testMembershipPermissions(
        ?MembershipRole $role,
        string $permission,
        int $expected
    ): void {
        $user = new User();
        $organization = new Organization();

        $membership = $role === null
            ? null
            : (new Membership())
                ->setUser($user)
                ->setOrganization($organization)
                ->setRole($role);

        $repository = $this->createMock(MembershipRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'user' => $user,
                'organization' => $organization,
            ])
            ->willReturn($membership);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new OrganizationVoter($repository);

        self::assertSame(
            $expected,
            $voter->vote($token, $organization, [$permission])
        );
    }

    public static function permissions(): iterable
    {
        foreach ([MembershipRole::Owner, MembershipRole::Manager] as $role) {
            yield $role->value.' views' => [
                $role, OrganizationVoter::VIEW, VoterInterface::ACCESS_GRANTED,
            ];
            yield $role->value.' edits' => [
                $role, OrganizationVoter::EDIT, VoterInterface::ACCESS_GRANTED,
            ];
        }

        yield 'viewer views' => [
            MembershipRole::Viewer,
            OrganizationVoter::VIEW,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'viewer cannot edit' => [
            MembershipRole::Viewer,
            OrganizationVoter::EDIT,
            VoterInterface::ACCESS_DENIED,
        ];
        yield 'nonmember cannot view' => [
            null, OrganizationVoter::VIEW, VoterInterface::ACCESS_DENIED,
        ];
        yield 'nonmember cannot edit' => [
            null, OrganizationVoter::EDIT, VoterInterface::ACCESS_DENIED,
        ];
    }
}