<?php

namespace App\Tests\Functional;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\ResetsTestDatabase;

final class OrganizationTest extends WebTestCase
{
    use ResetsTestDatabase;

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
}