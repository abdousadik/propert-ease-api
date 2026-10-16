<?php

namespace App\Tests\Functional;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrganizationTest extends WebTestCase
{
    public function testCreatorBecomesOrganizationOwner(): void
    {
        $client = static::createClient();
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('owner@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $em->persist($user);
        $em->flush();
        $userId = $user->getId();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        $client->jsonRequest(
            'POST',
            '/api/organizations',
            ['name' => 'Company A'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsInt($body['data']['id']);
        self::assertSame('Company A', $body['data']['name']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $organization = $em->find(Organization::class, $body['data']['id']);
        self::assertNotNull($organization);
        self::assertSame('Company A', $organization->getName());

        $membership = $em->getRepository(Membership::class)->findOneBy([
            'organization' => $organization,
            'user' => $em->find(User::class, $userId),
        ]);

        self::assertNotNull($membership);
        self::assertSame(MembershipRole::Owner, $membership->getRole());
        self::assertSame(1, $em->getRepository(Membership::class)->count([]));
    }

    public function testUnauthenticatedUserCannotCreateOrganization(): void
    {
        $client = static::createClient();
        $em = $this->resetTestDatabase();

        $client->jsonRequest(
            'POST',
            '/api/organizations',
            ['name' => 'Company A']
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $em->clear();

        self::assertSame(
            0,
            $em->getRepository(Organization::class)->count([])
        );
        self::assertSame(
            0,
            $em->getRepository(Membership::class)->count([])
        );
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameCreatesNoOrganization(
        mixed $name,
        bool $missing = false
    ): void
    {
        $client = static::createClient();
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('validation@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $em->persist($user);
        $em->flush();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        $client->request(
            'POST',
            '/api/organizations',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            content: json_encode(
                $missing ? new \stdClass() : ['name' => $name],
                JSON_THROW_ON_ERROR
            )
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(
            'Must be a nonblank string of at most 255 characters.',
            $body['error']['details']['name']
        );

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(0, $em->getRepository(Organization::class)->count([]));
        self::assertSame(0, $em->getRepository(Membership::class)->count([]));
    }

    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
        yield 'null' => [null];
        yield 'integer' => [123];
        yield 'array' => [['Company A']];
        yield 'too long' => [str_repeat('A', 256)];
        yield 'multibyte too long' => [str_repeat('é', 256)];
        yield 'missing name' => [null, true];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestCreatesNoOrganization(
        string $content,
        string $contentType,
        int $status,
        string $errorCode
    ): void {
        $client = static::createClient();
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('request-validation@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $em->persist($user);
        $em->flush();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        $client->request(
            'POST',
            '/api/organizations',
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

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(0, $em->getRepository(Organization::class)->count([]));
        self::assertSame(0, $em->getRepository(Membership::class)->count([]));
    }

    public static function invalidRequests(): iterable
    {
        yield 'malformed JSON' => [
            '{"name":', 'application/json', 400, 'invalid_json',
        ];
        yield 'array body' => [
            '[]', 'application/json', 400, 'invalid_body',
        ];
        yield 'null body' => [
            'null', 'application/json', 400, 'invalid_body',
        ];
        yield 'unsupported content type' => [
            '{"name":"Company A"}', 'text/plain', 415, 'unsupported_media_type',
        ];
        yield 'unknown field' => [
            '{"name":"Company A","role":"owner"}',
            'application/json',
            422,
            'validation_failed',
        ];
    }

    private function resetTestDatabase(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        self::assertSame(
            static::getContainer()->getParameter('kernel.project_dir').'/var/test.db',
            $em->getConnection()->getParams()['path'] ?? null
        );

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $em;
    }

    #[DataProvider('projectListPermissions')]
    public function testProjectReadsRespectOrganizationAccess(
        ?MembershipRole $role,
        int $expectedStatus
    ): void {
        $client = static::createClient();

        [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ] = $this->createProjectAccessFixtures($role);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
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

        if ($expectedStatus === 200) {
            self::assertSame(
                [$visibleProject->getId()],
                array_column($body['data'], 'id')
            );
            self::assertSame('Visible project', $body['data'][0]['name']);
        } else {
            self::assertSame('not_found', $body['error']['code']);
        }

        $baseUrl = '/api/organizations/'.$organization->getId().'/projects/';

        $client->request(
            'GET',
            $baseUrl.$visibleProject->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $detail = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if ($expectedStatus === 200) {
            self::assertSame($visibleProject->getId(), $detail['data']['id']);
        } else {
            self::assertSame('not_found', $detail['error']['code']);
        }

        foreach ([$foreignProject, $archivedProject] as $hiddenProject) {
            $client->request(
                'GET',
                $baseUrl.$hiddenProject->getId(),
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
            );

            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('content-type', 'application/json');

            $error = json_decode(
                $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame('not_found', $error['error']['code']);
        }
    }

    public static function projectListPermissions(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 200];
        yield 'manager' => [MembershipRole::Manager, 200];
        yield 'viewer' => [MembershipRole::Viewer, 200];
        yield 'nonmember' => [null, 404];
    }

    private function createProjectAccessFixtures(?MembershipRole $role): array
    {
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('project-access@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $organization = (new Organization())->setName('Company A');

        $em->persist($user);
        $em->persist($organization);

        if ($role !== null) {
            $membership = (new Membership())
                ->setUser($user)
                ->setOrganization($organization)
                ->setRole($role);

            $em->persist($membership);
        }

        $otherOrganization = (new Organization())->setName('Company B');
        $em->persist($otherOrganization);

        $visibleProject = (new Project())
            ->setOrganization($organization)
            ->setName('Visible project')
            ->setLabel('Residence')
            ->setNumberOfFloors(3)
            ->setAddress('Example address')
            ->setPostalCode('20000')
            ->setActive(true);

        $foreignProject = (new Project())
            ->setOrganization($otherOrganization)
            ->setName('Foreign project')
            ->setLabel('Residence')
            ->setNumberOfFloors(3)
            ->setAddress('Other address')
            ->setPostalCode('10000')
            ->setActive(true);

        $archivedProject = (new Project())
            ->setOrganization($organization)
            ->setName('Archived project')
            ->setLabel('Residence')
            ->setNumberOfFloors(3)
            ->setAddress('Archived address')
            ->setPostalCode('20000')
            ->setActive(false);

        foreach ([$visibleProject, $foreignProject, $archivedProject] as $project) {
            $em->persist($project);
        }

        $em->flush();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        return [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ];
    }

    #[DataProvider('projectListPermissions')]
    public function testProjectArchivingRespectsOrganizationAccess(
        ?MembershipRole $role,
        int $expectedStatus
    ): void {
        $client = static::createClient();

        [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ] = $this->createProjectAccessFixtures($role);

        $baseUrl = '/api/organizations/'.$organization->getId().'/projects/';

        $archiveStatus = match ($role) {
            MembershipRole::Owner, MembershipRole::Manager => 204,
            MembershipRole::Viewer => 403,
            null => 404,
        };

        $client->request(
            'DELETE',
            $baseUrl.$visibleProject->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($archiveStatus);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $storedProject = $em->find(Project::class, $visibleProject->getId());

        self::assertNotNull($storedProject);
        self::assertSame(
            $archiveStatus !== 204,
            $storedProject->isActive()
        );

        foreach ([$foreignProject, $archivedProject] as $hiddenProject) {
            $client->request(
                'DELETE',
                $baseUrl.$hiddenProject->getId(),
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
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
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertTrue(
            $em->find(Project::class, $foreignProject->getId())->isActive()
        );
        self::assertFalse(
            $em->find(Project::class, $archivedProject->getId())->isActive()
        );
    }

    #[DataProvider('projectListPermissions')]
    public function testProjectCreationRespectsOrganizationAccess(
        ?MembershipRole $role,
        int $expectedStatus
    ): void {
        $client = static::createClient();

        [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ] = $this->createProjectAccessFixtures($role);

        $createStatus = match ($role) {
            MembershipRole::Owner, MembershipRole::Manager => 201,
            MembershipRole::Viewer => 403,
            null => 404,
        };

        $client->jsonRequest(
            'POST',
            '/api/organizations/'.$organization->getId().'/projects',
            [
                'name' => 'New development',
                'label' => 'Residence',
                'numberOfFloors' => 3,
                'address' => 'Example address',
                'postalCode' => '20000',
            ],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($createStatus);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $created = $em->getRepository(Project::class)->findOneBy([
            'name' => 'New development',
        ]);

        if ($createStatus === 201) {
            self::assertNotNull($created);
            self::assertSame(
                $organization->getId(),
                $created->getOrganization()->getId()
            );
            self::assertTrue($created->isActive());
        } else {
            self::assertNull($created);
        }
    }

    #[DataProvider('invalidProjectFields')]
    public function testInvalidProjectDataIsNotSaved(
        string $field,
        mixed $value
    ): void {
        $client = static::createClient();

        [$organization, , , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $payload = [
            'name' => 'Rejected development',
            'label' => 'Residence',
            'numberOfFloors' => 3,
            'address' => 'Example address',
            'postalCode' => '20000',
        ];

        $payload[$field] = $value;

        $client->jsonRequest(
            'POST',
            '/api/organizations/'.$organization->getId().'/projects',
            $payload,
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertArrayHasKey($field, $body['error']['details']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        // The fixture contains three projects; no fourth should be created.
        self::assertSame(3, $em->getRepository(Project::class)->count([]));
    }

    public static function invalidProjectFields(): iterable
    {
        yield 'blank name' => ['name', '   '];
        yield 'oversized name' => ['name', str_repeat('é', 256)];
        yield 'invalid label type' => ['label', []];
        yield 'null address' => ['address', null];
        yield 'oversized postal code' => ['postalCode', '1234567'];
        yield 'numeric postal code' => ['postalCode', 20000];
        yield 'fractional floors' => ['numberOfFloors', 2.5];
        yield 'string floors' => ['numberOfFloors', '3'];
        yield 'scientific notation string' => ['numberOfFloors', '1e2'];
        yield 'negative floors' => ['numberOfFloors', -1];
        yield 'overflow floors' => ['numberOfFloors', 2147483648];
        yield 'client organization' => ['organizationId', 999];
        yield 'client active status' => ['active', false];
        yield 'impossible date' => ['deliveryDate', '2027-02-30 12:00:00'];
        yield 'wrong date format' => ['deliveryDate', '2027-06-01'];
        yield 'invalid date type' => ['deliveryDate', 123];
    }

    #[DataProvider('projectListPermissions')]
    public function testProjectUpdatingRespectsOrganizationAccess(
        ?MembershipRole $role,
        int $expectedStatus
    ): void {
        $client = static::createClient();

        [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ] = $this->createProjectAccessFixtures($role);

        $updateStatus = match ($role) {
            MembershipRole::Owner, MembershipRole::Manager => 200,
            MembershipRole::Viewer => 403,
            null => 404,
        };

        $baseUrl = '/api/organizations/'.$organization->getId().'/projects/';

        $client->jsonRequest(
            'PATCH',
            $baseUrl.$visibleProject->getId(),
            ['label' => 'Updated residence'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame($updateStatus);
        self::assertResponseHeaderSame('content-type', 'application/json');

        foreach ([$foreignProject, $archivedProject] as $hiddenProject) {
            $client->jsonRequest(
                'PATCH',
                $baseUrl.$hiddenProject->getId(),
                ['label' => 'Unauthorized change'],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
            );

            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('content-type', 'application/json');
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(
            $updateStatus === 200 ? 'Updated residence' : 'Residence',
            $em->find(Project::class, $visibleProject->getId())->getLabel()
        );

        foreach ([$foreignProject, $archivedProject] as $hiddenProject) {
            self::assertSame(
                'Residence',
                $em->find(Project::class, $hiddenProject->getId())->getLabel()
            );
        }
    }

    #[DataProvider('invalidProjectUpdates')]
    public function testRejectedUpdateLeavesProjectUnchanged(
        string $content,
        string $contentType,
        int $status,
        string $errorCode
    ): void {
        $client = static::createClient();

        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->request(
            'PATCH',
            '/api/organizations/'.$organization->getId()
                .'/projects/'.$project->getId(),
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

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $stored = $em->find(Project::class, $project->getId());

        self::assertNotNull($stored);
        self::assertSame('Residence', $stored->getLabel());
        self::assertSame(
            $organization->getId(),
            $stored->getOrganization()->getId()
        );
        self::assertTrue($stored->isActive());

        self::assertSame('Visible project', $stored->getName());
        self::assertSame('Example address', $stored->getAddress());
        self::assertSame('20000', $stored->getPostalCode());
        self::assertSame(3, $stored->getNumberOfFloors());

        self::assertNull($stored->getDeliveryDate());
    }

    public static function invalidProjectUpdates(): iterable
    {
        yield 'malformed JSON' => [
            '{"label":', 'application/json', 400, 'invalid_json',
        ];
        yield 'array body' => [
            '[]', 'application/json', 400, 'invalid_body',
        ];
        yield 'empty object' => [
            '{}', 'application/json', 422, 'validation_failed',
        ];
        yield 'blank label' => [
            '{"label":" "}', 'application/json', 422, 'validation_failed',
        ];
        yield 'null label' => [
            '{"label":null}', 'application/json', 422, 'validation_failed',
        ];
        yield 'organization override' => [
            '{"label":"Changed","organizationId":999}',
            'application/json',
            422,
            'validation_failed',
        ];
        yield 'active override' => [
            '{"label":"Changed","active":false}',
            'application/json',
            422,
            'validation_failed',
        ];
        yield 'unsupported content type' => [
            '{"label":"Changed"}', 'text/plain', 415, 'unsupported_media_type',
        ];
        yield 'valid label with fractional floors' => [
            '{"label":"Changed","numberOfFloors":2.5}',
            'application/json',
            422,
            'validation_failed',
        ];

        yield 'valid label with numeric postal code' => [
            '{"label":"Changed","postalCode":20000}',
            'application/json',
            422,
            'validation_failed',
        ];

        yield 'valid label with blank address' => [
            '{"label":"Changed","address":" "}',
            'application/json',
            422,
            'validation_failed',
        ];

        yield 'immutable name' => [
            '{"label":"Changed","name":"Renamed"}',
            'application/json',
            422,
            'validation_failed',
        ];

        yield 'valid label with impossible date' => [
            '{"label":"Changed","deliveryDate":"2027-02-30 12:00:00"}',
            'application/json',
            422,
            'validation_failed',
        ];

        yield 'date missing time' => [
            '{"deliveryDate":"2027-06-01"}',
            'application/json',
            422,
            'validation_failed',
        ];

        yield 'invalid date type' => [
            '{"deliveryDate":123}',
            'application/json',
            422,
            'validation_failed',
        ];
    }

    #[DataProvider('editableProjectFields')]
    public function testProjectFieldCanBeUpdated(
        string $field,
        mixed $value
    ): void {
        $client = static::createClient();

        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->jsonRequest(
            'PATCH',
            '/api/organizations/'.$organization->getId()
                .'/projects/'.$project->getId(),
            [$field => $value],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(200);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $stored = $em->find(Project::class, $project->getId());
        self::assertNotNull($stored);

        $getter = 'get'.ucfirst($field);
        self::assertSame($value, $stored->$getter());

        self::assertSame('Visible project', $stored->getName());
        self::assertSame(
            $organization->getId(),
            $stored->getOrganization()->getId()
        );
    }

    public static function editableProjectFields(): iterable
    {
        yield 'label' => ['label', 'Updated residence'];
        yield 'address' => ['address', 'New address'];
        yield 'postal code preserves zero' => ['postalCode', '01234'];
        yield 'floors' => ['numberOfFloors', 5];
        yield 'zero floors' => ['numberOfFloors', 0];
    }

    public function testDeliveryDateCanBeCreatedUpdatedAndCleared(): void
    {
        $client = static::createClient();

        [$organization, , , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $baseUrl = '/api/organizations/'.$organization->getId().'/projects';

        $client->jsonRequest(
            'POST',
            $baseUrl,
            [
                'name' => 'Scheduled development',
                'label' => 'Residence',
                'numberOfFloors' => 3,
                'address' => 'Example address',
                'postalCode' => '20000',
                'deliveryDate' => '2027-06-01 12:00:00',
            ],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(201);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $projectId = $body['data']['id'];

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(
            '2027-06-01 12:00:00',
            $em->find(Project::class, $projectId)
                ->getDeliveryDate()?->format('Y-m-d H:i:s')
        );

        foreach (['2027-07-15 09:30:00', null] as $date) {
            $client->jsonRequest(
                'PATCH',
                $baseUrl.'/'.$projectId,
                ['deliveryDate' => $date],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
            );

            self::assertResponseStatusCodeSame(200);

            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            self::assertSame(
                $date,
                $em->find(Project::class, $projectId)
                    ->getDeliveryDate()?->format('Y-m-d H:i:s')
            );
        }
    }

    #[DataProvider('projectNameFilters')]
    public function testNameSearchStaysWithinOrganization(
        string $name,
        array $expectedNames
    ): void {
        $client = static::createClient();

        [$organization, , , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: ['name' => $name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            $expectedNames,
            array_column($body['data'], 'name')
        );
    }

    public static function projectNameFilters(): iterable
    {
        yield 'partial match' => ['Visible', ['Visible project']];
        yield 'foreign project excluded' => ['Foreign', []];
        yield 'archived project excluded' => ['Archived', []];
        yield 'no match' => ['Missing', []];
        yield 'percent is literal' => ['%', []];
        yield 'underscore is literal' => ['_', []];
    }

    #[DataProvider('invalidNameFilters')]
    public function testInvalidNameFilterIsRejected(mixed $name): void
    {
        $client = static::createClient();

        [$organization, , , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: ['name' => $name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertArrayHasKey('name', $body['error']['details']);
    }

    public static function invalidNameFilters(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'array' => [['Visible']];
        yield 'oversized' => [str_repeat('é', 256)];
    }

    #[DataProvider('deliveryDateFilters')]
    public function testDeliveryDateSearchRespectsBounds(
        array $filters,
        bool $matches
    ): void {
        $client = static::createClient();

        [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ] = $this->createProjectAccessFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach ([$visibleProject, $foreignProject, $archivedProject] as $project) {
            $project->setDeliveryDate(
                new \DateTimeImmutable('2027-06-01 12:00:00', new \DateTimeZone('UTC'))
            );
        }

        $em->flush();

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: $filters,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(200);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            $matches ? [$visibleProject->getId()] : [],
            array_column($body['data'], 'id')
        );
    }

    public static function deliveryDateFilters(): iterable
    {
        yield 'inclusive minimum' => [
            ['deliveryDateMin' => '2027-06-01 12:00:00'], true,
        ];
        yield 'inclusive maximum' => [
            ['deliveryDateMax' => '2027-06-01 12:00:00'], true,
        ];
        yield 'after delivery' => [
            ['deliveryDateMin' => '2027-06-01 12:00:01'], false,
        ];
        yield 'before delivery' => [
            ['deliveryDateMax' => '2027-06-01 11:59:59'], false,
        ];
        yield 'within range' => [
            [
                'deliveryDateMin' => '2027-06-01 00:00:00',
                'deliveryDateMax' => '2027-06-30 23:59:59',
            ],
            true,
        ];
    }

    #[DataProvider('invalidDateFilters')]
    public function testInvalidDateFiltersAreRejected(
        array $filters,
        string $errorField
    ): void {
        $client = static::createClient();

        [$organization, , , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: $filters,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertArrayHasKey($errorField, $body['error']['details']);
    }

    public static function invalidDateFilters(): iterable
    {
        yield 'impossible minimum' => [
            ['deliveryDateMin' => '2027-02-30 12:00:00'],
            'deliveryDateMin',
        ];
        yield 'maximum missing time' => [
            ['deliveryDateMax' => '2027-06-01'],
            'deliveryDateMax',
        ];
        yield 'empty bound' => [
            ['deliveryDateMin' => ''],
            'deliveryDateMin',
        ];
        yield 'array bound' => [
            ['deliveryDateMax' => ['2027-06-01 12:00:00']],
            'deliveryDateMax',
        ];
        yield 'reversed range' => [
            [
                'deliveryDateMin' => '2027-07-01 00:00:00',
                'deliveryDateMax' => '2027-06-01 00:00:00',
            ],
            'deliveryDateMax',
        ];
    }

    public function testProjectsArePaginatedWithinOrganization(): void
    {
        $client = static::createClient();

        [$organization, $firstProject, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $projectIds = [$firstProject->getId()];

        foreach (['Second project', 'Third project'] as $name) {
            $project = (new Project())
                ->setOrganization($organization)
                ->setName($name)
                ->setLabel('Residence')
                ->setNumberOfFloors(3)
                ->setAddress('Example address')
                ->setPostalCode('20000')
                ->setActive(true);

            $em->persist($project);
            $em->flush();

            $projectIds[] = $project->getId();
        }

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: ['page' => '2', 'limit' => '1'],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            [$projectIds[1]],
            array_column($body['data'], 'id')
        );

        self::assertSame([
            'page' => 2,
            'limit' => 1,
            'total' => 3,
            'totalPages' => 3,
        ], $body['meta']);
    }

    #[DataProvider('invalidPaginationParameters')]
    public function testInvalidPaginationIsRejected(
        array $parameters,
        string $errorField
    ): void {
        $client = static::createClient();

        [$organization, , , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: $parameters,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertArrayHasKey($errorField, $body['error']['details']);
    }

    public static function invalidPaginationParameters(): iterable
    {
        yield 'zero page' => [['page' => '0'], 'page'];
        yield 'negative page' => [['page' => '-1'], 'page'];
        yield 'fractional page' => [['page' => '1.5'], 'page'];
        yield 'scientific notation' => [['page' => '1e2'], 'page'];
        yield 'leading zero' => [['page' => '01'], 'page'];
        yield 'empty page' => [['page' => ''], 'page'];
        yield 'array page' => [['page' => ['1']], 'page'];
        yield 'oversized page' => [['page' => '999999999999999999999'], 'page'];
        yield 'overflowing offset' => [
            ['page' => '2147483647', 'limit' => '100'], 'page',
        ];
        yield 'zero limit' => [['limit' => '0'], 'limit'];
        yield 'excessive limit' => [['limit' => '101'], 'limit'];
        yield 'array limit' => [['limit' => ['20']], 'limit'];
    }

    #[DataProvider('paginationScenarios')]
    public function testPaginationMetadata(
        array $parameters,
        bool $hasProject,
        array $expectedMeta
    ): void {
        $client = static::createClient();

        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);

        $client->request(
            'GET',
            '/api/organizations/'.$organization->getId().'/projects',
            parameters: $parameters,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(200);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            $hasProject ? [$project->getId()] : [],
            array_column($body['data'], 'id')
        );
        self::assertSame($expectedMeta, $body['meta']);
    }

    public static function paginationScenarios(): iterable
    {
        yield 'defaults' => [
            [],
            true,
            ['page' => 1, 'limit' => 20, 'total' => 1, 'totalPages' => 1],
        ];
        yield 'maximum limit accepted' => [
            ['limit' => '100'],
            true,
            ['page' => 1, 'limit' => 100, 'total' => 1, 'totalPages' => 1],
        ];
        yield 'page beyond last' => [
            ['page' => '2', 'limit' => '1'],
            false,
            ['page' => 2, 'limit' => 1, 'total' => 1, 'totalPages' => 1],
        ];
        yield 'filtered empty results' => [
            ['name' => 'Missing'],
            false,
            ['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0],
        ];
        yield 'foreign project excluded from count' => [
            ['name' => 'Foreign'],
            false,
            ['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0],
        ];
        yield 'undated project excluded from date count' => [
            ['deliveryDateMin' => '2027-01-01 00:00:00'],
            false,
            ['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0],
        ];
    }
}