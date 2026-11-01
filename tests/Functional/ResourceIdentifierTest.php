<?php

namespace App\Tests\Functional;

use App\Enum\MembershipRole;
use App\Tests\Support\CreatesProjectAccessFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResourceIdentifierTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

    #[DataProvider('oversizedRoutes')]
    public function testOversizedResourceIdentifierReturnsJson404(string $method, string $path): void
    {
        $client = static::createClient();
        [$organization, $project, , , $token] = $this->createProjectAccessFixtures(MembershipRole::Owner);
        $path = strtr($path, ['{org}' => (string) $organization->getId(),
            '{project}' => (string) $project->getId(), '{huge}' => str_repeat('9', 30)]);
        $client->jsonRequest($method, $path, [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['error' => ['code' => 'not_found', 'message' => 'Resource not found.']],
            json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function oversizedRoutes(): iterable
    {
        foreach (['GET', 'POST'] as $method) {
            yield "$method project list organization" => [$method, '/api/organizations/{huge}/projects'];
            yield "$method memberships organization" => [$method, '/api/organizations/{huge}/memberships'];
        }
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            yield "$method project organization" => [$method, '/api/organizations/{huge}/projects/{project}'];
            yield "$method project ID" => [$method, '/api/organizations/{org}/projects/{huge}'];
        }
        foreach (['PATCH', 'DELETE'] as $method) {
            yield "$method membership organization" => [$method, '/api/organizations/{huge}/memberships/1'];
            yield "$method membership ID" => [$method, '/api/organizations/{org}/memberships/{huge}'];
        }
    }

    public function testAuthenticationStillPrecedesIdentifierValidation(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/organizations/'.str_repeat('9', 30).'/projects');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLeadingZerosPreserveLookupAndTenantIsolation(): void
    {
        $client = static::createClient();
        [$organization, $project, $foreignProject, , $token] = $this->createProjectAccessFixtures(MembershipRole::Owner);
        $url = '/api/organizations/'.str_repeat('0', 30).$organization->getId().'/projects/';
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $client->request('GET', $url.str_repeat('0', 30).$project->getId(), server: $headers);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($project->getId(), $body['data']['id']);
        $client->request('GET', $url.'000'.$foreignProject->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
    }
}
