<?php

namespace App\Tests\Support;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

trait CreatesProjectAccessFixtures
{
    use ResetsTestDatabase;

    /**
     * @return array{Organization, Project, Project, Project, string}
     */
    private function createProjectAccessFixtures(
        ?MembershipRole $role
    ): array {
        $em = $this->resetTestDatabase();

        $user = (new User())
            ->setEmail('project-access@example.com')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setPhone('0600000000')
            ->setPassword('unused-test-hash');

        $organization = (new Organization())->setName('Company A');

        $em->persist($user);
        $em->persist($organization);

        if ($role !== null) {
            $membership = (new Membership())
                ->setUser($user)
                ->setOrganization($organization)
                ->setRole($role);

            $em->persist($membership);
        }

        $otherOrganization = (new Organization())->setName('Company B');
        $em->persist($otherOrganization);

        $visibleProject = (new Project())
            ->setOrganization($organization)
            ->setName('Visible project')
            ->setLabel('Residence')
            ->setNumberOfFloors(3)
            ->setAddress('Example address')
            ->setPostalCode('20000')
            ->setActive(true);

        $foreignProject = (new Project())
            ->setOrganization($otherOrganization)
            ->setName('Foreign project')
            ->setLabel('Residence')
            ->setNumberOfFloors(3)
            ->setAddress('Other address')
            ->setPostalCode('10000')
            ->setActive(true);

        $archivedProject = (new Project())
            ->setOrganization($organization)
            ->setName('Archived project')
            ->setLabel('Residence')
            ->setNumberOfFloors(3)
            ->setAddress('Archived address')
            ->setPostalCode('20000')
            ->setActive(false);

        foreach ([$visibleProject, $foreignProject, $archivedProject] as $project) {
            $em->persist($project);
        }

        $em->flush();

        $token = static::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        return [
            $organization,
            $visibleProject,
            $foreignProject,
            $archivedProject,
            $token,
        ];
    }
}