<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

class ProjectOwnershipTest extends ApiTestCase
{
    public function testOwnerCrudAndForeignAccess(): void
    {
        $owner = $this->token($this->user());
        $other = $this->token($this->user('other@example.com'));
        $created = $this->request('POST', '/api/project', $this->projectData(), $owner);
        self::assertResponseStatusCodeSame(201);
        $id = $created['data']['id'];
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $body = $this->request($method, '/api/project/'.$id, $method === 'PATCH' ? ['label' => 'Changed'] : null, $other);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('not_found', $body['error']['code']);
        }
        foreach (['/api/project', '/api/project/search'] as $uri) {
            $body = $this->request('GET', $uri, null, $other);
            self::assertSame([], $body['data']);
            self::assertSame(0, $body['meta']['total']);
        }
        $body = $this->request('PATCH', '/api/project/'.$id, ['label' => 'Changed'], $owner);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Changed', $body['data']['label']);
        self::assertSame('Garden Homes', $body['data']['name']);
        $this->request('DELETE', '/api/project/'.$id, null, $owner);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $this->request($method, '/api/project/'.$id, $method === 'PATCH' ? ['label' => 'Again'] : null, $owner);
            self::assertResponseStatusCodeSame(404);
        }
        $body = $this->request('GET', '/api/project/search', null, $owner);
        self::assertSame(0, $body['meta']['total']);
    }

    public function testMissingAndInvalidIds(): void
    {
        $token = $this->token($this->user());
        foreach (['999', 'abc', '0', '-1', '9999999999999999999999'] as $id) {
            $this->request('GET', '/api/project/'.$id, null, $token);
            self::assertResponseStatusCodeSame(404);
        }
    }
}
