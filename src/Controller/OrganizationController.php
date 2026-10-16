<?php

namespace App\Controller;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class OrganizationController extends AbstractController
{
    #[Route('/organizations', name: 'create_organization', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(
                ['error' => ['code' => 'unauthorized', 'message' => 'Login required.']],
                401
            );
        }

        $contentType = strtolower(trim(
            explode(';', $request->headers->get('Content-Type', ''), 2)[0]
        ));

        if ($contentType !== 'application/json') {
            return $this->json([
                'error' => [
                    'code' => 'unsupported_media_type',
                    'message' => 'Content-Type must be application/json.',
                ],
            ], 415);
        }

        try {
            $data = json_decode(
                $request->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return $this->json(
                ['error' => ['code' => 'invalid_json', 'message' => 'Malformed JSON body.']],
                400
            );
        }

        if (!$data instanceof \stdClass) {
            return $this->json(
                ['error' => ['code' => 'invalid_body', 'message' => 'Expected a JSON object.']],
                400
            );
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