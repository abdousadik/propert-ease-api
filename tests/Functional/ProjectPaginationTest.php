<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Enum\MembershipRole;
use App\Tests\Support\CreatesProjectAccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectPaginationTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

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