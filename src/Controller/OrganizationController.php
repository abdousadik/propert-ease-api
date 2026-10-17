<?php

namespace App\Controller;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\JsonRequestDecoder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class OrganizationController extends AbstractController
{
    #[Route('/organizations', name: 'create_organization', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(
                ['error' => ['code' => 'unauthorized', 'message' => 'Login required.']],
                401
            );
        }

        $data = $jsonDecoder->decode($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $errors = [];

        foreach (get_object_vars($data) as $field => $value) {
            if ($field !== 'name') {
                $errors[$field] = 'Unknown field.';
            }
        }

        if ($errors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid organization data.',
                    'details' => $errors,
                ],
            ], 422);
        }

        $name = $data->name ?? null;

        if (
            !is_string($name)
            || trim($name) === ''
            || mb_strlen(trim($name), 'UTF-8') > 255
        ) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid organization data.',
                    'details' => [
                        'name' => 'Must be a nonblank string of at most 255 characters.',
                    ],
                ],
            ], 422);
        }

        $organization = (new Organization())->setName(trim($name));

        $membership = (new Membership())
            ->setUser($user)
            ->setOrganization($organization)
            ->setRole(MembershipRole::Owner);

        $em->persist($organization);
        $em->persist($membership);
        $em->flush();

        return $this->json([
            'data' => [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
            ],
        ], 201);
    }
}