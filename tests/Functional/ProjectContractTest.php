<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ProjectContractTest extends ApiTestCase
{
    #[DataProvider('invalidFields')]
    public function testCreateValidation(string $field, mixed $value): void
    {
        $token = $this->token($this->user());
        $body = $this->request('POST', '/api/project', array_replace($this->projectData(), [$field => $value]), $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey($field, $body['error']['details']);
    }

    public static function invalidFields(): array
    {
        return [['numberOfFloors', 1.5], ['numberOfFloors', '1e2'], ['numberOfFloors', -1], ['numberOfFloors', 2147483648], ['numberOfFloors', true], ['postalCode', 12345], ['postalCode', '1234567'], ['deliveryDate', '2027-02-30 12:00:00'], ['deliveryDate', []], ['name', []], ['label', null], ['address', str_repeat('a', 256)], ['owner', 1]];
    }

    public function testPatchAndPayloadFailures(): void
    {
        $token = $this->token($this->user());
        $created = $this->request('POST', '/api/project', $this->projectData(), $token);
        self::assertResponseStatusCodeSame(201);
        $uri = '/api/project/'.$created['data']['id'];
        foreach (['{}', '{', '[]', 'null', ''] as $raw) {
            $this->client->request('PATCH', $uri, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $raw);
            self::assertResponseStatusCodeSame(400);
        }
        foreach ([['name' => 'Renamed'], ['label' => null], ['numberOfFloors' => 1.5], ['deliveryDate' => '2027-02-30 00:00:00']] as $patch) {
            $this->request('PATCH', $uri, $patch, $token);
            self::assertResponseStatusCodeSame(422);
        }
        $body = $this->request('GET', $uri, null, $token);
        self::assertSame('Garden Homes', $body['data']['name']);
        self::assertSame('Residential', $body['data']['label']);
        $this->request('POST', '/api/project?name=QueryOnly', (object) [], $token);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/api/project', [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], 'hello');
        self::assertResponseStatusCodeSame(415);
    }
}
