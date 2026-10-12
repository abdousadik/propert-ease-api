<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class LoginTest extends WebTestCase
{
    public function testValidCredentialsReturnToken(): void
    {
        $client = static::createClient();

        $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'login@example.com',
            'password' => 'LocalDemoPassword123!',
        ]);

        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/login_check', [
            'username' => 'login@example.com',
            'password' => 'LocalDemoPassword123!',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayHasKey('token', $body);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);

        $client->request(
            'GET',
            '/api/project',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$body['token'],
            ]
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testUnknownUserCannotLogIn(): void
    {
        $client = static::createClient();

        $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/login_check', [
            'username' => 'missing@example.com',
            'password' => 'LocalDemoPassword123!',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayNotHasKey('token', $body);
    }

    private function resetTestDatabase(): void
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
    }

    public function testWrongPasswordCannotLogIn(): void
    {
        $client = static::createClient();
        $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'login@example.com',
            'password' => 'LocalDemoPassword123!',
        ]);

        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/login_check', [
            'username' => 'login@example.com',
            'password' => 'WrongPassword123!',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayNotHasKey('token', $body);
    }

    public function testInvalidTokenCannotAccessProjects(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/project',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer not-a-valid-jwt',
            ]
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(401, $body['code']);
        self::assertSame('Invalid JWT Token', $body['message']);
    }

    public function testExpiredTokenCannotAccessProjects(): void
    {
        $client = static::createClient();

        $user = new User();
        $user->setEmail('expired@example.com');
        $user->setRoles(['ROLE_USER']);

        $jwtManager = static::getContainer()->get(
            JWTTokenManagerInterface::class
        );

        $now = time();
        $token = $jwtManager->createFromPayload($user, [
            'iat' => $now - 7200,
            'exp' => $now - 3600,
        ]);

        $client->request(
            'GET',
            '/api/project',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ]
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(401, $body['code']);
        self::assertSame('Expired JWT Token', $body['message']);
    }
}