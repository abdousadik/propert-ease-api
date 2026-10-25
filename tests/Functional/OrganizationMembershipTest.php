<?php

namespace App\Tests\Functional;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Tests\Support\ResetsTestDatabase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Exception\MembershipChangeRejected;
use App\Service\MembershipManager;

final class OrganizationMembershipTest extends WebTestCase
{
    use ResetsTestDatabase;

    #[DataProvider('membershipListPermissions')]
    public function testMembershipListRequiresOrganizationAccess(
        ?MembershipRole $role,
        int $expectedStatus
    ): void {
        $client = static::createClient();
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('member@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $organization = (new Organization())->setName('Company A');
        $otherOrganization = (new Organization())->setName('Company B');

        $em->persist($user);
        $em->persist($organization);
        $em->persist($otherOrganization);

        $foreignMembership = (new Membership())
            ->setUser($user)
            ->setOrganization($otherOrganization)
            ->setRole(MembershipRole::Owner);

        $em->persist($foreignMembership);

        $membership = null;

        if ($role !== null) {
            $membership = (new Membership())
                ->setUser($user)
                ->setOrganization($organization)
                ->setRole($role);

            $em->persist($membership);
        }

        $em->flush();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/memberships',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if ($role === null) {
            self::assertSame('not_found', $body['error']['code']);

            return;
        }

        self::assertSame([
            'data' => [
                [
                    'id' => $membership->getId(),
                    'user' => [
                        'id' => $user->getId(),
                        'firstName' => 'Demo',
                        'lastName' => 'User',
                    ],
                    'role' => $role->value,
                ],
            ],
        ], $body);
    }

    public static function membershipListPermissions(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 200];
        yield 'manager' => [MembershipRole::Manager, 200];
        yield 'viewer' => [MembershipRole::Viewer, 200];
        yield 'nonmember owning another organization' => [null, 404];
    }

    #[DataProvider('memberCreationPermissions')]
    public function testAddingMemberRequiresOwner(
        ?MembershipRole $actorRole,
        int $expectedStatus
    ): void
    {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures($actorRole);

        $memberId = $member->getId();
        $organizationId = $organization->getId();

        $client->jsonRequest(
            'POST',
            '/api/organizations/'.$organizationId.'/memberships',
            [
                'email' => 'new-member@example.com',
                'role' => 'viewer',
            ],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        $membership = $em->getRepository(Membership::class)->findOneBy([
            'user' => $em->find(User::class, $memberId),
            'organization' => $em->find(Organization::class, $organizationId),
        ]);

        if ($expectedStatus === 201) {
            self::assertNotNull($membership);
            self::assertSame(MembershipRole::Viewer, $membership->getRole());
        } else {
            self::assertNull($membership);
        }

        self::assertSame(
            $expectedStatus === 201 ? 2 : ($actorRole === null ? 0 : 1),
            $em->getRepository(Membership::class)->count([])
        );

        if ($expectedStatus !== 201) {
            return;
        }

        $client->jsonRequest(
            'POST',
            '/api/organizations/'.$organizationId.'/memberships',
            [
                'email' => '  New-Member@Example.COM  ',
                'role' => 'manager',
            ],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            'membership_already_exists',
            $body['error']['code']
        );

        $connection = static::getContainer()
            ->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getConnection();

        self::assertSame(
            2,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM membership')
        );

        self::assertSame(
            'viewer',
            $connection->fetchOne(
                'SELECT role FROM membership WHERE user_id = ? AND organization_id = ?',
                [$memberId, $organizationId]
            )
        );
    }

    public static function memberCreationPermissions(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 201];
        yield 'manager' => [MembershipRole::Manager, 403];
        yield 'viewer' => [MembershipRole::Viewer, 403];
        yield 'nonmember' => [null, 404];
    }

    /**
     * @return array{User, Organization, string}
     */
    private function createMemberAdministrationFixtures(
        ?MembershipRole $actorRole
    ): array {
        $em = $this->resetTestDatabase();

        $owner = (new User())
            ->setEmail('owner@example.com')
            ->setFirstName('Owner')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $member = (new User())
            ->setEmail('new-member@example.com')
            ->setFirstName('New')
            ->setLastName('Member')
            ->setPhone('0600000001')
            ->setPassword('unused-test-hash');

        $organization = (new Organization())->setName('Company A');

        foreach ([$owner, $member, $organization] as $entity) {
            $em->persist($entity);
        }

        if ($actorRole !== null) {
            $actorMembership = (new Membership())
                ->setUser($owner)
                ->setOrganization($organization)
                ->setRole($actorRole);

            $em->persist($actorMembership);
        }

        $em->flush();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($owner);

        return [$member, $organization, $token];
    }

    #[DataProvider('invalidMembershipData')]
    public function testInvalidMemberAdditionCreatesNothing(
        array $payload,
        int $status,
        string $errorCode
    ): void {
        $client = static::createClient();

        [, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $client->jsonRequest(
            'POST',
            '/api/organizations/'.$organization->getId().'/memberships',
            $payload,
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($errorCode, $body['error']['code']);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertSame(1, $em->getRepository(Membership::class)->count([]));
    }

    public static function invalidMembershipData(): iterable
    {
        yield 'missing email' => [
            ['role' => 'viewer'], 422, 'validation_failed',
        ];
        yield 'invalid email type' => [
            ['email' => [], 'role' => 'viewer'], 422, 'validation_failed',
        ];
        yield 'invalid email format' => [
            ['email' => 'invalid', 'role' => 'viewer'], 422, 'validation_failed',
        ];
        yield 'missing role' => [
            ['email' => 'new-member@example.com'], 422, 'validation_failed',
        ];
        yield 'unknown role' => [
            ['email' => 'new-member@example.com', 'role' => 'admin'],
            422,
            'validation_failed',
        ];
        yield 'invalid role type' => [
            ['email' => 'new-member@example.com', 'role' => []],
            422,
            'validation_failed',
        ];
        yield 'unknown field' => [
            [
                'email' => 'new-member@example.com',
                'role' => 'viewer',
                'organizationId' => 999,
            ],
            422,
            'validation_failed',
        ];
        yield 'unregistered user' => [
            ['email' => 'missing@example.com', 'role' => 'viewer'],
            404,
            'user_not_found',
        ];
    }

    #[DataProvider('memberRoleChangePermissions')]
    public function testChangingRoleRequiresOwner(
        ?MembershipRole $actorRole,
        int $expectedStatus
    ): void {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures($actorRole);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $membership = (new Membership())
            ->setUser($member)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Viewer);

        $em->persist($membership);
        $em->flush();
        $membershipId = $membership->getId();

        $client->jsonRequest(
            'PATCH',
            '/api/organizations/'.$organization->getId()
                .'/memberships/'.$membershipId,
            ['role' => 'manager'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        $stored = $em->find(Membership::class, $membershipId);

        self::assertNotNull($stored);
        self::assertSame(
            $expectedStatus === 200
                ? MembershipRole::Manager
                : MembershipRole::Viewer,
            $stored->getRole()
        );
    }

    public static function memberRoleChangePermissions(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 200];
        yield 'manager' => [MembershipRole::Manager, 403];
        yield 'viewer' => [MembershipRole::Viewer, 403];
        yield 'nonmember' => [null, 404];
    }

    public function testLastOwnerCannotBeDemoted(): void
    {
        $client = static::createClient();

        [, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $ownerMembership = $em->getRepository(Membership::class)->findOneBy([
            'organization' => $organization,
            'role' => MembershipRole::Owner,
        ]);

        self::assertNotNull($ownerMembership);
        $membershipId = $ownerMembership->getId();

        $client->jsonRequest(
            'PATCH',
            '/api/organizations/'.$organization->getId()
                .'/memberships/'.$membershipId,
            ['role' => 'viewer'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('last_owner', $body['error']['code']);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertSame(
            MembershipRole::Owner,
            $em->find(Membership::class, $membershipId)->getRole()
        );
    }

    public function testLastOwnerCannotBeRemoved(): void
    {
        $client = static::createClient();

        [, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $ownerMembership = $em->getRepository(Membership::class)->findOneBy([
            'organization' => $organization,
            'role' => MembershipRole::Owner,
        ]);

        self::assertNotNull($ownerMembership);
        $membershipId = $ownerMembership->getId();

        $client->request(
            'DELETE',
            '/api/organizations/'.$organization->getId()
                .'/memberships/'.$membershipId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('last_owner', $body['error']['code']);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertSame(
            MembershipRole::Owner,
            $em->find(Membership::class, $membershipId)->getRole()
        );
    }

    #[DataProvider('foreignMembershipOperations')]
    public function testOwnerCannotModifyForeignMembership(string $method): void
    {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $otherOrganization = (new Organization())->setName('Company B');

        $foreignMembership = (new Membership())
            ->setUser($member)
            ->setOrganization($otherOrganization)
            ->setRole(MembershipRole::Viewer);

        $em->persist($otherOrganization);
        $em->persist($foreignMembership);
        $em->flush();

        $membershipId = $foreignMembership->getId();

        $client->request(
            $method,
            '/api/organizations/'.$organization->getId()
                .'/memberships/'.$membershipId,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            content: $method === 'PATCH' ? '{"role":"owner"}' : null
        );

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('not_found', $body['error']['code']);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertSame(
            MembershipRole::Viewer,
            $em->find(Membership::class, $membershipId)->getRole()
        );
    }

    public static function foreignMembershipOperations(): iterable
    {
        yield 'change role' => ['PATCH'];
        yield 'remove' => ['DELETE'];
    }

    #[DataProvider('invalidRoleChanges')]
    public function testRejectedRoleChangePreservesMembership(
        string $content,
        string $contentType,
        int $status,
        string $errorCode
    ): void {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $membership = (new Membership())
            ->setUser($member)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Viewer);

        $em->persist($membership);
        $em->flush();
        $membershipId = $membership->getId();

        $client->request(
            'PATCH',
            '/api/organizations/'.$organization->getId()
                .'/memberships/'.$membershipId,
            server: [
                'CONTENT_TYPE' => $contentType,
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            content: $content
        );

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($errorCode, $body['error']['code']);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertSame(
            MembershipRole::Viewer,
            $em->find(Membership::class, $membershipId)->getRole()
        );
    }

    public static function invalidRoleChanges(): iterable
    {
        yield 'malformed JSON' => [
            '{"role":', 'application/json', 400, 'invalid_json',
        ];
        yield 'non-object body' => [
            '[]', 'application/json', 400, 'invalid_body',
        ];
        yield 'missing role' => [
            '{}', 'application/json', 422, 'validation_failed',
        ];
        yield 'unknown role' => [
            '{"role":"admin"}', 'application/json', 422, 'validation_failed',
        ];
        yield 'wrong role type' => [
            '{"role":[]}', 'application/json', 422, 'validation_failed',
        ];
        yield 'unknown field with valid role' => [
            '{"role":"manager","userId":999}',
            'application/json', 422, 'validation_failed',
        ];
        yield 'unsupported content type' => [
            '{"role":"manager"}', 'text/plain', 415, 'unsupported_media_type',
        ];
    }

    public function testOwnerCanStepDownWhenAnotherOwnerRemains(): void
    {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $originalOwner = $em->getRepository(Membership::class)->findOneBy([
            'organization' => $organization,
            'role' => MembershipRole::Owner,
        ]);

        self::assertNotNull($originalOwner);
        $originalOwnerId = $originalOwner->getId();

        $secondOwner = (new Membership())
            ->setUser($member)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Owner);

        $em->persist($secondOwner);
        $em->flush();
        $secondOwnerId = $secondOwner->getId();

        $baseUrl = '/api/organizations/'.$organization->getId().'/memberships/';

        $client->jsonRequest(
            'PATCH',
            $baseUrl.$originalOwnerId,
            ['role' => 'viewer'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(200);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertSame(
            MembershipRole::Viewer,
            $em->find(Membership::class, $originalOwnerId)->getRole()
        );
        self::assertSame(
            MembershipRole::Owner,
            $em->find(Membership::class, $secondOwnerId)->getRole()
        );

        // The same JWT must no longer grant Owner permissions.
        $client->jsonRequest(
            'PATCH',
            $baseUrl.$secondOwnerId,
            ['role' => 'viewer'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanLeaveWhenAnotherOwnerRemains(): void
    {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $originalOwner = $em->getRepository(Membership::class)->findOneBy([
            'organization' => $organization,
            'role' => MembershipRole::Owner,
        ]);

        self::assertNotNull($originalOwner);
        $originalOwnerId = $originalOwner->getId();

        $secondOwner = (new Membership())
            ->setUser($member)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Owner);

        $em->persist($secondOwner);
        $em->flush();
        $secondOwnerId = $secondOwner->getId();

        $baseUrl = '/api/organizations/'.$organization->getId().'/memberships/';

        $client->request(
            'DELETE',
            $baseUrl.$originalOwnerId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        self::assertNull(
            $em->find(Membership::class, $originalOwnerId)
        );
        self::assertSame(
            MembershipRole::Owner,
            $em->find(Membership::class, $secondOwnerId)->getRole()
        );

        // The same JWT must no longer grant Owner permissions.
        $client->jsonRequest(
            'PATCH',
            $baseUrl.$secondOwnerId,
            ['role' => 'viewer'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('memberRemovalPermissions')]
    public function testRemovingMemberRequiresOwner(
        ?MembershipRole $actorRole,
        int $expectedStatus
    ): void {
        $client = static::createClient();

        [$member, $organization, $token] =
            $this->createMemberAdministrationFixtures($actorRole);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $membership = (new Membership())
            ->setUser($member)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Viewer);

        $em->persist($membership);
        $em->flush();

        $membershipId = $membership->getId();
        $memberId = $member->getId();

        $client->request(
            'DELETE',
            '/api/organizations/'.$organization->getId()
                .'/memberships/'.$membershipId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($expectedStatus);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->clear();

        $stored = $em->find(Membership::class, $membershipId);

        if ($expectedStatus === 204) {
            self::assertNull($stored);
            self::assertSame('', $client->getResponse()->getContent());
        } else {
            self::assertNotNull($stored);
            self::assertSame(MembershipRole::Viewer, $stored->getRole());
        }

        self::assertNotNull($em->find(User::class, $memberId));
    }

    public static function memberRemovalPermissions(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 204];
        yield 'manager' => [MembershipRole::Manager, 403];
        yield 'viewer' => [MembershipRole::Viewer, 403];
        yield 'nonmember' => [null, 404];
    }

    #[DataProvider('membershipMutationOperations')]
    public function testServiceRechecksRevokedOwnerPermission(
        string $operation
    ): void {
        static::createClient();

        [$member, $organization] =
            $this->createMemberAdministrationFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $actorMembership = $em->getRepository(Membership::class)->findOneBy([
            'organization' => $organization,
            'role' => MembershipRole::Owner,
        ]);

        self::assertNotNull($actorMembership);
        $actorId = $actorMembership->getUser()->getId();

        $target = (new Membership())
            ->setUser($member)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Owner);

        $em->persist($target);
        $em->flush();

        $connection = $em->getConnection();

        $connection->update(
            'membership',
            ['role' => 'viewer'],
            ['id' => $actorMembership->getId()]
        );

        // The ORM object is stale; the database now says Viewer.
        self::assertSame(MembershipRole::Owner, $actorMembership->getRole());

        $manager = static::getContainer()->get(MembershipManager::class);

        try {
            match ($operation) {
                'add' => $manager->add(
                    $organization->getId(),
                    $actorId,
                    $member->getEmail(),
                    MembershipRole::Viewer
                ),
                'change' => $manager->change(
                    $organization->getId(),
                    $target->getId(),
                    $actorId,
                    MembershipRole::Viewer
                ),
                'remove' => $manager->remove(
                    $organization->getId(),
                    $target->getId(),
                    $actorId
                ),
            };

            self::fail('A former Owner must not administer memberships.');
        } catch (MembershipChangeRejected $exception) {
            self::assertSame(403, $exception->status);
            self::assertSame('forbidden', $exception->errorCode);
        }

        self::assertSame(
            2,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM membership')
        );
        self::assertSame(
            'owner',
            $connection->fetchOne(
                'SELECT role FROM membership WHERE id = ?',
                [$target->getId()]
            )
        );
    }

    public static function membershipMutationOperations(): iterable
    {
        yield 'add' => ['add'];
        yield 'change role' => ['change'];
        yield 'remove' => ['remove'];
    }
}