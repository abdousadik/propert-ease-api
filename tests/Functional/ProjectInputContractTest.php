<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Enum\MembershipRole;
use App\Tests\Support\CreatesProjectAccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectInputContractTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

    #[DataProvider('rejectedInputs')]
    public function testRejectedInputPreservesExactResponseAndStoredState(
        string $method,
        string $content,
        string $contentType,
        int $status,
        array $expectedError
    ): void {
        $client = static::createClient();
        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);
        $this->setExistingDeliveryDate($project);
        $before = $this->storedProjects();
        $url = '/api/organizations/'.$organization->getId().'/projects';

        $client->request(
            $method,
            $method === 'POST' ? $url : $url.'/'.$project->getId(),
            server: ['CONTENT_TYPE' => $contentType, 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            content: $content
        );

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['error' => $expectedError], json_decode(
            $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        ));
        self::assertSame($before, $this->storedProjects(), 'Rejected input must not change any project.');
    }

    public static function rejectedInputs(): iterable
    {
        $text = 'Must be a nonblank string of at most 255 characters.';
        $postal = 'Must be a nonblank string of at most 6 characters.';
        $floors = 'Must be an integer between 0 and 2147483647.';
        $date = 'Must be null or a valid UTC date in YYYY-MM-DD HH:MM:SS format.';
        $validation = static fn (array $details): array => [
            'code' => 'validation_failed',
            'message' => 'Invalid project data.',
            'details' => $details,
        ];

        yield 'create requires every non-date field' => [
            'POST', '{}', 'application/json', 422,
            $validation(['name' => $text, 'label' => $text, 'address' => $text,
                'postalCode' => $postal, 'numberOfFloors' => $floors]),
        ];
        yield 'create rejects null required fields' => [
            'POST', '{"name":null,"label":null,"address":null,"postalCode":null,"numberOfFloors":null}',
            'application/json', 422,
            $validation(['name' => $text, 'label' => $text, 'address' => $text,
                'postalCode' => $postal, 'numberOfFloors' => $floors]),
        ];
        yield 'create error ordering and messages' => [
            'POST', '{"deliveryDate":123,"active":false,"label":[],"name":" ","numberOfFloors":2.5,"address":null,"postalCode":20000}',
            'application/json', 422,
            $validation(['name' => $text, 'label' => $text, 'address' => $text,
                'postalCode' => $postal, 'numberOfFloors' => $floors,
                'active' => 'Unknown field.', 'deliveryDate' => $date]),
        ];
        yield 'patch requires at least one field' => [
            'PATCH', '{}', 'application/json', 422,
            $validation(['body' => 'Provide at least one editable field.']),
        ];
        yield 'patch null date cannot clear data when other fields are invalid' => [
            'PATCH', '{"deliveryDate":null,"label":null,"numberOfFloors":null,"name":"Renamed","address":null,"postalCode":null}',
            'application/json', 422,
            $validation(['label' => $text, 'numberOfFloors' => $floors,
                'name' => 'Unknown field.', 'address' => $text, 'postalCode' => $postal]),
        ];
        yield 'patch error ordering and messages' => [
            'PATCH', '{"deliveryDate":"2027-02-30 12:00:00","label":" ","numberOfFloors":2147483648,"id":42}',
            'application/json', 422,
            $validation(['deliveryDate' => $date, 'label' => $text,
                'numberOfFloors' => $floors, 'id' => 'Unknown field.']),
        ];
        yield 'create malformed JSON' => [
            'POST', '{"name":', 'application/json', 400,
            ['code' => 'invalid_json', 'message' => 'Malformed JSON body.'],
        ];
        yield 'create requires object' => [
            'POST', '[]', 'application/json', 400,
            ['code' => 'invalid_body', 'message' => 'Expected a JSON object.'],
        ];
        yield 'create requires JSON content type' => [
            'POST', '{}', 'text/plain', 415,
            ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.'],
        ];
    }

    #[DataProvider('accessPrecedence')]
    public function testAccessChecksStillPrecedeInvalidInput(
        ?MembershipRole $role,
        string $method,
        bool $foreignTarget,
        int $status,
        string $code,
        string $message
    ): void {
        $client = static::createClient();
        [$organization, $project, $foreignProject, , $token] =
            $this->createProjectAccessFixtures($role);
        $url = '/api/organizations/'.$organization->getId().'/projects';
        if ($method === 'PATCH') {
            $url .= '/'.($foreignTarget ? $foreignProject : $project)->getId();
        }

        $client->request($method, $url, server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: '{"label":');

        self::assertResponseStatusCodeSame($status);
        self::assertSame(['error' => ['code' => $code, 'message' => $message]], json_decode(
            $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        ));
    }

    public static function accessPrecedence(): iterable
    {
        foreach (['POST', 'PATCH'] as $method) {
            yield "viewer $method" => [MembershipRole::Viewer, $method, false, 403,
                'forbidden', 'You cannot modify projects in this organization.'];
            yield "nonmember $method" => [null, $method, false, 404,
                'not_found', 'Organization not found.'];
        }
        yield 'owner foreign target' => [MembershipRole::Owner, 'PATCH', true, 404,
            'not_found', 'Project not found.'];
        yield 'viewer foreign target before edit permission' => [MembershipRole::Viewer, 'PATCH', true, 404,
            'not_found', 'Project not found.'];
    }

    #[DataProvider('successfulInputs')]
    public function testTextNormalizationBoundariesAndDatePresence(
        string $method,
        bool $includeNullDate
    ): void {
        $client = static::createClient();
        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);
        $this->setExistingDeliveryDate($project);
        $url = '/api/organizations/'.$organization->getId().'/projects';
        $payload = [
            'label' => '  '.str_repeat('é', 255).'  ',
            'address' => '  0  ',
            'postalCode' => '  012345  ',
            'numberOfFloors' => $method === 'POST' ? 0 : 2147483647,
        ];
        if ($method === 'POST') {
            $payload['name'] = '  '.str_repeat('é', 255).'  ';
        }
        if ($includeNullDate) {
            $payload['deliveryDate'] = null;
        }

        $client->jsonRequest($method, $method === 'POST' ? $url : $url.'/'.$project->getId(),
            $payload, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame($method === 'POST' ? 201 : 200);
        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsInt($body['data']['id']);
        self::assertSame(['data' => [
            'id' => $body['data']['id'],
            $method === 'POST' ? 'name' : 'label' => str_repeat('é', 255),
        ]], $body);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(Project::class, $body['data']['id']);
        self::assertNotNull($stored);
        self::assertSame($method === 'POST' ? str_repeat('é', 255) : 'Visible project', $stored->getName());
        self::assertSame(str_repeat('é', 255), $stored->getLabel());
        self::assertSame('0', $stored->getAddress());
        self::assertSame('012345', $stored->getPostalCode());
        self::assertSame($payload['numberOfFloors'], $stored->getNumberOfFloors());
        self::assertSame($organization->getId(), $stored->getOrganization()->getId());
        self::assertTrue($stored->isActive());
        self::assertSame($method === 'PATCH' && !$includeNullDate ? '2027-06-01 12:00:00' : null,
            $stored->getDeliveryDate()?->format('Y-m-d H:i:s'));
    }

    public static function successfulInputs(): iterable
    {
        yield 'create date omitted' => ['POST', false];
        yield 'create date null' => ['POST', true];
        yield 'patch date omitted preserves existing date' => ['PATCH', false];
        yield 'patch date null clears existing date' => ['PATCH', true];
    }

    private function setExistingDeliveryDate(Project $project): void
    {
        $project->setDeliveryDate(new \DateTimeImmutable('2027-06-01 12:00:00', new \DateTimeZone('UTC')));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function storedProjects(): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllAssociative('SELECT * FROM project ORDER BY id');
    }
}
