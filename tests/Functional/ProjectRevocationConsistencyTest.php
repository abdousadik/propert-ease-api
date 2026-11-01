<?php

namespace App\Tests\Functional;

use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Service\MembershipManager;
use App\Tests\Support\CreatesProjectAccessFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectRevocationConsistencyTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

    #[DataProvider('inFlightWrites')]
    public function testAuthorizedWriteCanFinishButNextWriteUsesCurrentMembership(
        string $method,
        int $expectedStatus,
        string $revocation
    ): void {
        $client = static::createClient();
        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Manager);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $actor = $em->getRepository(User::class)->findOneBy(['email' => 'project-access@example.com']);
        $membership = $em->getRepository(Membership::class)->findOneBy([
            'user' => $actor, 'organization' => $organization,
        ]);
        self::assertNotNull($membership);
        $actorId = $actor->getId();
        $membershipId = $membership->getId();

        $owner = (new User())->setEmail('revocation-owner@example.com')->setFirstName('Demo')
            ->setLastName('Owner')->setPhone('0600000000')->setPassword('unused-test-hash');
        $ownerMembership = (new Membership())->setUser($owner)->setOrganization($organization)
            ->setRole(MembershipRole::Owner);
        $em->persist($owner);
        $em->persist($ownerMembership);
        $em->flush();

        $other = DriverManager::getConnection($em->getConnection()->getParams());
        self::assertSame(static::getContainer()->getParameter('kernel.project_dir').'/var/test.db',
            $other->getParams()['path'] ?? null);

        // onFlush runs after controller authorization and before project SQL.
        // The second connection commits a real membership mutation at that point.
        $listener = new class($other, $organization->getId(), $membershipId, $owner->getId(), $revocation) {
            public bool $revocationCommitted = false;

            public function __construct(
                private readonly Connection $connection,
                private readonly int $organizationId,
                private readonly int $membershipId,
                private readonly int $ownerId,
                private readonly string $revocation
            ) {
            }

            public function onFlush(): void
            {
                if ($this->revocationCommitted) {
                    return;
                }
                $manager = new MembershipManager($this->connection);
                if ($this->revocation === 'remove') {
                    $manager->remove($this->organizationId, $this->membershipId, $this->ownerId);
                } else {
                    $manager->change($this->organizationId, $this->membershipId, $this->ownerId, MembershipRole::Viewer);
                }
                $this->revocationCommitted = !$this->connection->isTransactionActive();
            }
        };
        $em->getEventManager()->addEventListener(['onFlush'], $listener);

        try {
            $baseUrl = '/api/organizations/'.$organization->getId().'/projects';
            $payload = ['name' => 'In-flight development', 'label' => 'Authorized label',
                'address' => 'Example address', 'postalCode' => '20000', 'numberOfFloors' => 1];
            $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
            $client->jsonRequest($method, $method === 'POST' ? $baseUrl : $baseUrl.'/'.$project->getId(),
                $method === 'PATCH' ? ['label' => $payload['label']] : $payload, $headers);

            self::assertResponseStatusCodeSame($expectedStatus);
            self::assertTrue($listener->revocationCommitted);
            self::assertSame($revocation === 'remove' ? false : 'viewer',
                $other->fetchOne('SELECT role FROM membership WHERE id = ?', [$membershipId]));
            self::assertSame($actorId, (int) $other->fetchOne('SELECT id FROM user WHERE id = ?', [$actorId]));

            $projectId = $project->getId();
            if ($method === 'POST') {
                $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $projectId = $body['data']['id'];
            }
            $stored = $other->fetchAssociative('SELECT label, active, organization_id FROM project WHERE id = ?', [$projectId]);
            self::assertIsArray($stored);
            self::assertSame($method === 'DELETE' ? 'Residence' : 'Authorized label', $stored['label']);
            self::assertSame($method === 'DELETE' ? 0 : 1, (int) $stored['active']);
            self::assertSame($organization->getId(), (int) $stored['organization_id']);

            // A fresh request with the same valid JWT sees the revoked permission.
            $before = $other->fetchAllAssociative('SELECT * FROM project ORDER BY id');
            $client->jsonRequest('POST', $baseUrl, $payload, $headers);
            self::assertResponseStatusCodeSame($revocation === 'remove' ? 404 : 403);
            self::assertSame($before, $other->fetchAllAssociative('SELECT * FROM project ORDER BY id'));
        } finally {
            $em->getEventManager()->removeEventListener(['onFlush'], $listener);
            $other->close();
        }
    }

    public static function inFlightWrites(): iterable
    {
        foreach (['POST' => 201, 'PATCH' => 200, 'DELETE' => 204] as $method => $status) {
            foreach (['remove', 'demote'] as $revocation) {
                yield "$method with $revocation" => [$method, $status, $revocation];
            }
        }
    }
}
