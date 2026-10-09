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
    
}