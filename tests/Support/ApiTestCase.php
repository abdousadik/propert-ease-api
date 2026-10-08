<?php

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function request(string $method, string $uri, mixed $body = null, ?string $token = null): mixed
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, [], [], $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        return $this->client->getResponse()->getContent() === '' ? [] : json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function user(string $email = 'owner@example.com'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email)->setFirstName('Ada')->setLastName('Example')->setPhone('0123456789')->setRoles(['ROLE_USER']);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'long-test-password'));
        $em->persist($user);
        $em->flush();
        return $user;
    }

    protected function token(User $user): string
    {
        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    protected function projectData(): array
    {
        return ['name' => 'Garden Homes', 'label' => 'Residential', 'numberOfFloors' => 3, 'address' => '12 Garden Street', 'postalCode' => '01234', 'deliveryDate' => '2027-06-30 12:00:00'];
    }
}
