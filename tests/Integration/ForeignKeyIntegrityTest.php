<?php

namespace App\Tests\Integration;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Tests\Support\ResetsTestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ForeignKeyIntegrityTest extends KernelTestCase
{
    use ResetsTestDatabase;

    public function testForeignKeysRemainEnabledAfterReconnecting(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(static::getContainer()->getParameter('kernel.project_dir').'/var/test.db',
            $connection->getParams()['path'] ?? null);
        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        $connection->close();
        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
    }

    #[DataProvider('invalidRelationships')]
    public function testDatabaseRejectsInvalidRelationships(string $operation): void
    {
        self::bootKernel();
        $em = $this->resetTestDatabase();
        $connection = $em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        $user = (new User())->setEmail('integrity@example.com')->setFirstName('Demo')
            ->setLastName('User')->setPhone('0600000000')->setPassword('unused-test-hash');
        $org = (new Organization())->setName('Integrity fixtures');
        $membership = (new Membership())->setUser($user)->setOrganization($org)->setRole(MembershipRole::Owner);
        $project = (new Project())->setOrganization($org)->setName('Valid project')->setLabel('Residence')
            ->setAddress('Example address')->setPostalCode('20000')->setNumberOfFloors(1)->setActive(true);
        foreach ([$user, $org, $membership, $project] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $before = $this->state($connection);
        $connection->beginTransaction();
        try {
            try {
                match ($operation) {
                    'orphan project' => $connection->update('project', ['organization_id' => 2147483647], ['id' => $project->getId()]),
                    'orphan membership user' => $connection->insert('membership', ['user_id' => 2147483647, 'organization_id' => $org->getId(), 'role' => 'viewer']),
                    'orphan membership organization' => $connection->insert('membership', ['user_id' => $user->getId(), 'organization_id' => 2147483647, 'role' => 'viewer']),
                    'referenced organization' => $connection->delete('organization', ['id' => $org->getId()]),
                    'referenced user' => $connection->delete('user', ['id' => $user->getId()]),
                };
                self::fail('The foreign-key constraint must reject '.$operation);
            } catch (ForeignKeyConstraintViolationException) {
                self::assertSame($before, $this->state($connection));
            }
        } finally {
            $connection->rollBack();
        }
        self::assertSame($before, $this->state($connection));
    }

    public static function invalidRelationships(): iterable
    {
        foreach (['orphan project', 'orphan membership user', 'orphan membership organization',
            'referenced organization', 'referenced user'] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function state(Connection $connection): array
    {
        $state = [];
        foreach (['user', 'organization', 'membership', 'project'] as $table) {
            $state[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        return $state;
    }
}
