<?php

namespace App\Tests\Functional;

use App\Enum\MembershipRole;
use App\Tests\Support\CreatesProjectAccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectSearchTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

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
}