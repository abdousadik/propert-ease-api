<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginInputValidationTest extends WebTestCase
{
    #[DataProvider('invalidBodies')]
    public function testInvalidLoginBodyReturnsSafeJson400(string $content): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/login_check', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], content: $content);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['error' => [
            'code' => 'invalid_login_data',
            'message' => 'Expected a JSON object with string username and password fields.',
        ]], json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function invalidBodies(): iterable
    {
        yield 'malformed JSON' => ['{"username":'];
        yield 'empty body' => [''];
        yield 'array' => ['[]'];
        yield 'null' => ['null'];
        yield 'missing both fields' => ['{}'];
        yield 'missing username' => ['{"password":"MustNeverBeEchoed123!"}'];
        yield 'missing password' => ['{"username":"private-user@example.com"}'];
        yield 'array username' => ['{"username":[],"password":"MustNeverBeEchoed123!"}'];
        yield 'array password' => ['{"username":"private-user@example.com","password":[]}'];
        yield 'boolean username' => ['{"username":true,"password":"MustNeverBeEchoed123!"}'];
    }

    #[DataProvider('emptyCredentials')]
    public function testEmptyStringCredentialsRetainJson401(array $payload): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/login_check', $payload);
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(401, $body['code']);
        self::assertArrayNotHasKey('token', $body);
    }

    public static function emptyCredentials(): iterable
    {
        yield 'empty username' => [['username' => '', 'password' => 'ExamplePassword123!']];
        yield 'empty password' => [['username' => 'example@example.com', 'password' => '']];
    }
}
