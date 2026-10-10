<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SignupTest extends WebTestCase
{
    #[DataProvider('invalidSignupFieldTypes')]
    public function testSignupRejectsInvalidFieldTypes(
        string $field,
        mixed $invalidValue
    ): void {
        $client = static::createClient();

        $payload = [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'validation-demo@example.com',
            'password' => 'LocalDemoPassword123!',
        ];

        $payload[$field] = $invalidValue;

        $client->jsonRequest('POST', '/api/signup', $payload);

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
            'Must be a string.',
            $body['error']['details'][$field]
        );
    }

    public static function invalidSignupFieldTypes(): iterable
    {
        $invalidValues = [
            'array' => ['invalid'],
            'object' => (object) ['value' => 'invalid'],
            'integer' => 123,
            'boolean' => true,
            'null' => null,
        ];

        foreach (['firstName', 'lastName', 'phone', 'email', 'password'] as $field) {
            foreach ($invalidValues as $type => $value) {
                yield "$field: $type" => [$field, $value];
            }
        }
    }

    #[DataProvider('requiredSignupFields')]
    public function testSignupRequiresEachField(string $field): void
    {
        $client = static::createClient();

        $payload = [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'validation-demo@example.com',
            'password' => 'LocalDemoPassword123!',
        ];

        unset($payload[$field]);

        $client->jsonRequest('POST', '/api/signup', $payload);

        self::assertResponseStatusCodeSame(422);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame('Required.', $body['error']['details'][$field]);
    }

    public static function requiredSignupFields(): iterable
    {
        foreach (['firstName', 'lastName', 'phone', 'email', 'password'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('blankSignupTextFields')]
    public function testSignupRejectsBlankTextFields(
        string $field,
        string $value
    ): void {
        $client = static::createClient();

        $payload = [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'validation-demo@example.com',
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
        self::assertSame(
            'Must not be blank.',
            $body['error']['details'][$field]
        );
    }

    public static function blankSignupTextFields(): iterable
    {
        foreach (['firstName', 'lastName', 'phone', 'email'] as $field) {
            foreach (['empty' => '', 'spaces' => '   ', 'tabs/newlines' => "\t\n"] as $label => $value) {
                yield "$field: $label" => [$field, $value];
            }
        }
    }

    #[DataProvider('oversizedSignupFields')]
    public function testSignupRejectsOversizedFields(
        string $field,
        string $value,
        int $limit
    ): void {
        $client = static::createClient();

        $payload = [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => 'validation-demo@example.com',
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
        self::assertSame(
            "Must be at most $limit characters.",
            $body['error']['details'][$field]
        );
    }

    public static function oversizedSignupFields(): iterable
    {
        yield 'first name' => ['firstName', str_repeat('A', 101), 100];
        yield 'multibyte first name' => ['firstName', str_repeat('é', 101), 100];
        yield 'last name' => ['lastName', str_repeat('A', 101), 100];
        yield 'phone' => ['phone', str_repeat('1', 21), 20];
        yield 'email' => [
            'email',
            str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 49).'.com',
            180,
        ];
    }

    #[DataProvider('invalidEmailAddresses')]
    public function testSignupRejectsInvalidEmailAddresses(string $email): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/signup', [
            'firstName' => 'Demo',
            'lastName' => 'User',
            'phone' => '0600000000',
            'email' => $email,
            'password' => 'LocalDemoPassword123!',
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
            'Must be a valid email address.',
            $body['error']['details']['email']
        );
    }

    public static function invalidEmailAddresses(): iterable
    {
        yield 'missing at sign' => ['demo.example.com'];
        yield 'missing local part' => ['@example.com'];
        yield 'missing domain' => ['demo@'];
        yield 'multiple at signs' => ['demo@@example.com'];
        yield 'internal space' => ['demo user@example.com'];
        yield 'consecutive dots' => ['demo..user@example.com'];
    }

    #[DataProvider('invalidJsonBodies')]
    public function testSignupRejectsInvalidJsonBodies(
        string $content,
        string $expectedCode
    ): void {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/signup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $content
        );

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($expectedCode, $body['error']['code']);
    }

    public static function invalidJsonBodies(): iterable
    {
        yield 'malformed object' => ['{"firstName":', 'invalid_json'];
        yield 'empty body' => ['', 'invalid_json'];
        yield 'array' => ['[]', 'invalid_body'];
        yield 'null' => ['null', 'invalid_body'];
        yield 'string' => ['"Demo"', 'invalid_body'];
        yield 'number' => ['123', 'invalid_body'];
        yield 'boolean' => ['true', 'invalid_body'];
    }

    #[DataProvider('unsupportedSignupContentTypes')]
    public function testSignupRejectsUnsupportedContentTypes(string $contentType): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/signup',
            [],
            [],
            ['CONTENT_TYPE' => $contentType],
            '{}'
        );

        self::assertResponseStatusCodeSame(415);

        $body = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('unsupported_media_type', $body['error']['code']);
    }

    public static function unsupportedSignupContentTypes(): iterable
    {
        yield 'plain text' => ['text/plain'];
        yield 'form data' => ['application/x-www-form-urlencoded'];
        yield 'missing type' => [''];
    }
}