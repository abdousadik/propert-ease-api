<?php

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\Container;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

trait ResetsTestDatabase
{
    abstract protected static function getContainer(): Container;

    private function resetTestDatabase(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        Assert::assertSame(
            static::getContainer()->getParameter('kernel.project_dir').'/var/test.db',
            $em->getConnection()->getParams()['path'] ?? null
        );

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $em;
    }
}