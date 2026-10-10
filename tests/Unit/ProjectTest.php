<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    public function testProjectCanBeDeactivated(): void
    {
        // ARRANGE
        $project = new Project();
        $project->setActive(true);

        // ACT
        $project->setActive(false);
        $status = $project->isActive();

        // ASSERT
        self::assertFalse($status);
    }

    public function testProjectCanBeActivated(): void
    {
        // ARRANGE
        $project = new Project();
        $project->setActive(false);

        // ACT
        $project->setActive(true);
        $status = $project->isActive();

        // ASSERT
        self::assertTrue($status);
    }
}