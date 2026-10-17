<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Enum\MembershipRole;
use App\Tests\Support\CreatesProjectAccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrganizationProjectTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

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
}