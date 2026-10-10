<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

        self::assertResponseStatusCodeSame(200);

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
        self::assertResponseStatusCodeSame(200);

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
}