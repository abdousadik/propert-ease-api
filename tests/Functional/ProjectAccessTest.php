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
        $client->request('GET', '/api/project');

        // ASSERT
        self::assertResponseStatusCodeSame(401);
    }
}