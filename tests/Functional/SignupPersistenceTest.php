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

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Verify the database target before rebuilding its tables.
        self::assertSame(
            static::getContainer()->getParameter('kernel.project_dir').'/var/test.db',
            $em->getConnection()->getParams()['path'] ?? null
        );

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

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
}