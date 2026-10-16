<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectAccessTest extends WebTestCase
{
    public function testUnauthenticatedUserCannotListProjects(): void
    {
        // ARRANGE
        $client = static::createClient();

        // ACT
        $client->request('GET', '/api/organizations/1/projects');

        // ASSERT
        self::assertResponseStatusCodeSame(401);
    }
}