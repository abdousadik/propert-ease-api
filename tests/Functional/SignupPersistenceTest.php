<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SignupPersistenceTest extends WebTestCase
{
    #[DataProvider('emailAddressesToNormalize')]
    public function testSignupNormalizesEmail(string $email): void
    {
        $client = static::createClient();

        $em = $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => $email,
            'password' => 'LocalDemoPassword123!',
        ]);

        self::assertResponseStatusCodeSame(201);

        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy([
            'email' => 'demo@example.com',
        ]);

        self::assertNotNull($user);
        self::assertSame('demo@example.com', $user->getEmail());
    }

    public static function emailAddressesToNormalize(): iterable
    {
        yield 'mixed case' => ['Demo@Example.COM'];
        yield 'surrounding spaces' => ['  Demo@Example.COM  '];
        yield 'surrounding tabs and newlines' => ["\tDemo@Example.COM\n"];
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

    #[DataProvider('emailAddressesToNormalize')]
    public function testSignupRejectsDuplicateEmail(string $email): void
    {
        $client = static::createClient();
        $this->resetTestDatabase();

        $payload = [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'demo@example.com',
            'password' => 'LocalDemoPassword123!',
        ];

        $client->jsonRequest('POST', '/api/signup', $payload);
        self::assertResponseStatusCodeSame(201);

        $payload['email'] = $email;

        $client->jsonRequest('POST', '/api/signup', $payload);
        self::assertResponseStatusCodeSame(409);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('email_already_used', $body['error']['code']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(1, $em->getRepository(User::class)->count([]));
    }

    #[DataProvider('validPasswords')]
    public function testSignupCreatesUserWithHashedPassword(string $password): void
    {
        $client = static::createClient();
        $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'demo@example.com',
            'password' => $password,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy([
            'email' => 'demo@example.com',
        ]);

        self::assertNotNull($user);

        self::assertSame([
            'data' => [
                'id' => $user->getId(),
                'email' => 'demo@example.com',
                'firstName' => 'Demo',
                'lastName' => 'User',
                'phone' => '0600000000',
            ],
        ], $body);

        self::assertNotSame($password, $user->getPassword());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, $password));
    }

    public static function validPasswords(): iterable
    {
        yield 'minimum length' => [str_repeat('A', 12)];
        yield 'multibyte minimum' => [str_repeat('é', 12)];
        yield 'intentional surrounding spaces' => ['  DemoPassword123!  '];
        yield 'maximum length' => [str_repeat('A', 128)];
        yield 'multibyte maximum' => [str_repeat('é', 128)];
    }

    #[DataProvider('shortPasswords')]
    public function testSignupRejectsShortPasswords(string $password): void
    {
        $client = static::createClient();

        $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'password-demo@example.com',
            'password' => $password,
        ]);

        self::assertResponseStatusCodeSame(422);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(
            'Must be at least 12 characters.',
            $body['error']['details']['password']
        );
    }

    public static function shortPasswords(): iterable
    {
        yield 'empty' => [''];
        yield 'one character' => ['A'];
        yield 'just below minimum' => [str_repeat('A', 11)];
        yield 'multibyte characters' => [str_repeat('é', 11)];
    }

    #[DataProvider('oversizedPasswords')]
    public function testSignupRejectsOversizedPasswords(string $password): void
    {
        $client = static::createClient();

        $this->resetTestDatabase();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => hash('sha256', $password).'@example.com',
            'password' => $password,
        ]);

        self::assertResponseStatusCodeSame(422);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(
            'Must be at most 128 characters.',
            $body['error']['details']['password']
        );
    }

    public static function oversizedPasswords(): iterable
    {
        yield 'ASCII' => [str_repeat('A', 129)];
        yield 'multibyte' => [str_repeat('é', 129)];
    }

    #[DataProvider('unknownSignupFields')]
    public function testSignupRejectsUnknownFields(
        string $field,
        mixed $value
    ): void {
        $client = static::createClient();
        $this->resetTestDatabase();

        $payload = [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'unknown-field@example.com',
            'password' => 'LocalDemoPassword123!',
        ];

        $payload[$field] = $value;

        $client->jsonRequest('POST', '/api/signup', $payload);

        self::assertResponseStatusCodeSame(422);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame('Unknown field.', $body['error']['details'][$field]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(0, $em->getRepository(User::class)->count([]));
    }

    public static function unknownSignupFields(): iterable
    {
        yield 'client-supplied roles' => ['roles', ['ROLE_ADMIN']];
        yield 'client-supplied ID' => ['id', 123];
        yield 'misspelled field' => ['fristName', 'Demo'];
    }
}