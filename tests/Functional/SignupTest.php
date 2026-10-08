<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SignupTest extends ApiTestCase
{
    private function signupData(): array
    {
        return ['firstName' => 'Ada', 'lastName' => 'Example', 'phone' => '0123456789', 'email' => 'ADA@example.com', 'password' => 'long-test-password'];
    }

    public function testJsonSignupCreatesUser(): void
    {
        $body = $this->request('POST', '/api/signup', $this->signupData());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ada@example.com', $body['data']['email']);
        self::assertArrayNotHasKey('password', $body['data']);
        $login = $this->request('POST', '/api/login_check', ['username' => 'ada@example.com', 'password' => 'long-test-password']);
        self::assertResponseIsSuccessful();
        self::assertIsString($login['token']);
        $this->request('GET', '/api/project', null, $login['token']);
        self::assertResponseIsSuccessful();
    }

    public function testMalformedJson(): void
    {
        $this->client->request('POST', '/api/signup', [], [], ['CONTENT_TYPE' => 'application/json'], '{');
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', json_decode($this->client->getResponse()->getContent(), true));
    }

    #[DataProvider('invalidFields')]
    public function testRejectsUnknownAndInvalidFields(string $field, mixed $value): void
    {
        $this->request('POST', '/api/signup', array_replace($this->signupData(), [$field => $value]));
        self::assertResponseStatusCodeSame(422);
    }

    public static function invalidFields(): array
    {
        return [['firstName', []], ['lastName', null], ['phone', str_repeat('1', 21)], ['email', 'not-email'], ['password', 'short'], ['owner', 1], ['firstName', '   ']];
    }

    public function testDuplicateNormalizedEmail(): void
    {
        $this->user('ada@example.com');
        $body = $this->request('POST', '/api/signup', $this->signupData());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('email_conflict', $body['error']['code']);
    }

    public function testProtectedRouteRequiresJwt(): void
    {
        $this->request('GET', '/api/project');
        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidCredentials(): void
    {
        $this->user();
        $this->request('POST', '/api/login_check', ['username' => 'owner@example.com', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDatabaseDuplicateRaceReturnsConflict(): void
    {
        $connection = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
        if ($connection->getDatabasePlatform()->getName() !== 'sqlite') {
            self::markTestSkipped('SQLite trigger simulates the database uniqueness race; MySQL duplicate coverage uses the regular signup test.');
        }
        // The competing row appears after the controller's precheck, inside the actual INSERT.
        $connection->executeStatement("CREATE TRIGGER concurrent_signup BEFORE INSERT ON user BEGIN INSERT INTO user(email, roles, password, first_name, last_name, phone) VALUES(NEW.email, NEW.roles, NEW.password, NEW.first_name, NEW.last_name, NEW.phone); END");
        $body = $this->request('POST', '/api/signup', $this->signupData());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('email_conflict', $body['error']['code']);
        self::assertStringNotContainsString('SQL', $body['error']['message']);
    }
}
