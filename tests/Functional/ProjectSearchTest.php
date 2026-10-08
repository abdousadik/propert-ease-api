<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

class ProjectSearchTest extends ApiTestCase
{
    public function testPaginationAndQuerySearch(): void
    {
        $token = $this->token($this->user());
        $empty = $this->request('GET', '/api/project/search', null, $token);
        self::assertSame([], $empty['data']);
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 0], $empty['meta']);
        for ($i = 0; $i < 3; ++$i) {
            $this->request('POST', '/api/project', array_replace($this->projectData(), ['name' => 'Garden '.$i]), $token);
            self::assertResponseStatusCodeSame(201);
        }
        $first = $this->request('GET', '/api/project?limit=2', null, $token);
        $second = $this->request('GET', '/api/project?page=2&limit=2', null, $token);
        self::assertCount(2, $first['data']);
        self::assertCount(1, $second['data']);
        self::assertSame(3, $second['meta']['total']);
        self::assertGreaterThan($first['data'][1]['id'], $second['data'][0]['id']);
        $query = http_build_query(['name' => 'Garden 1', 'deliveryDateMin' => '2027-06-30 12:00:00', 'deliveryDateMax' => '2027-06-30 12:00:00']);
        $found = $this->request('GET', '/api/project/search?'.$query, null, $token);
        self::assertSame(1, $found['meta']['total']);
        self::assertSame('Garden 1', $found['data'][0]['name']);
        $padded = $this->request('GET', '/api/project/search?name=%20Garden%201%20', null, $token);
        self::assertSame(1, $padded['meta']['total']);
        self::assertSame('Garden 1', $padded['data'][0]['name']);
        $body = $this->request('GET', '/api/project?limit=100', null, $token);
        self::assertSame(100, $body['meta']['limit']);
    }

    public function testInvalidQueryParameters(): void
    {
        $token = $this->token($this->user());
        foreach (['limit=101', 'page=0', 'page=1e2', 'page=999999999999999999999', 'page[]=1', 'name[]=a', 'name=%FF', 'unknown=1', 'deliveryDateMin=2027-02-30%2000:00:00', 'deliveryDateMin=2028-01-01%2000:00:00&deliveryDateMax=2027-01-01%2000:00:00'] as $query) {
            $this->request('GET', '/api/project/search?'.$query, null, $token);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testMalformedQueryFieldNameReturnsJsonError(): void
    {
        $token = $this->token($this->user());
        $this->client->request('GET', '/api/project/search?bad%FF=value', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
