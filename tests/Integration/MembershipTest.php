<?php

namespace App\Tests\Integration;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class MembershipTest extends KernelTestCase
{
    public function testUserCanHaveDifferentRolesAcrossOrganizations(): void
    {
        self::bootKernel();

        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('member@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $companyA = (new Organization())->setName('Company A');
        $companyB = (new Organization())->setName('Company B');

        $owner = (new Membership())
            ->setUser($user)
            ->setOrganization($companyA)
            ->setRole(MembershipRole::Owner);

        $viewer = (new Membership())
            ->setUser($user)
            ->setOrganization($companyB);

        foreach ([$user, $companyA, $companyB, $owner, $viewer] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $ownerId = $owner->getId();
        $viewerId = $viewer->getId();
        $em->clear();

        $storedOwner = $em->find(Membership::class, $ownerId);
        $storedViewer = $em->find(Membership::class, $viewerId);

        self::assertNotNull($storedOwner);
        self::assertNotNull($storedViewer);
        self::assertSame(MembershipRole::Owner, $storedOwner->getRole());
        self::assertSame(MembershipRole::Viewer, $storedViewer->getRole());
        self::assertSame(
            $storedOwner->getUser()->getId(),
            $storedViewer->getUser()->getId()
        );
        self::assertNotSame(
            $storedOwner->getOrganization()->getId(),
            $storedViewer->getOrganization()->getId()
        );
    }

    public function testDuplicateMembershipIsRejected(): void
    {
        self::bootKernel();
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('duplicate-member@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $organization = (new Organization())->setName('Company A');

        $membership = (new Membership())
            ->setUser($user)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Owner);

        foreach ([$user, $organization, $membership] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $duplicate = (new Membership())
            ->setUser($user)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Viewer);

        $em->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);

        $em->flush();
    }

    private function resetTestDatabase(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        self::assertSame(
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