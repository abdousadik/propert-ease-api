<?php

namespace App\Controller;

use App\ValueResolver\ResourceIdValueResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Security\Voter\OrganizationVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Exception\MembershipChangeRejected;
use App\Service\MembershipManager;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Service\JsonRequestDecoder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationMembershipController extends AbstractController
{
    #[Route(
        '/organizations/{organizationId}/memberships',
        name: 'organization_memberships_list',
        requirements: ['organizationId' => '\d+'],
        methods: ['GET']
    )]
    public function list(
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        EntityManagerInterface $em
    ): JsonResponse {
        $organization = $em->find(Organization::class, $organizationId);

        if (
            $organization === null
            || !$this->isGranted(OrganizationVoter::VIEW, $organization)
        ) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Organization not found.',
                ],
            ], 404);
        }

        $memberships = $em->getRepository(Membership::class)
            ->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->where('m.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        $data = [];

        foreach ($memberships as $membership) {
            $user = $membership->getUser();

            $data[] = [
                'id' => $membership->getId(),
                'user' => [
                    'id' => $user->getId(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                ],
                'role' => $membership->getRole()->value,
            ];
        }

        return $this->json(['data' => $data]);
    }

    #[Route(
        '/organizations/{organizationId}/memberships',
        name: 'organization_memberships_create',
        requirements: ['organizationId' => '\d+'],
        methods: ['POST']
    )]
    public function create(
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder,
        MembershipManager $membershipManager
    ): JsonResponse {
        $organization = $em->find(Organization::class, $organizationId);

        if (
            $organization === null
            || !$this->isGranted(OrganizationVoter::VIEW, $organization)
        ) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Organization not found.',
                ],
            ], 404);
        }
        
        if (!$this->isGranted(OrganizationVoter::MANAGE_MEMBERS, $organization)) {
            return $this->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Only Owners can manage memberships.',
                ],
            ], 403);
        }

        $data = $jsonDecoder->decode($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $errors = [];

        foreach (get_object_vars($data) as $field => $value) {
            if (!in_array($field, ['email', 'role'], true)) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $email = $data->email ?? null;

        if (is_string($email)) {
            $email = strtolower(trim($email));
        }

        if (
            !is_string($email)
            || mb_strlen($email, 'UTF-8') > 180
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'Must be a valid email address of at most 180 characters.';
        }

        $roleValue = $data->role ?? null;
        $role = is_string($roleValue)
            ? MembershipRole::tryFrom($roleValue)
            : null;

        if ($role === null) {
            $errors['role'] = 'Must be owner, manager, or viewer.';
        }

        if ($errors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid membership data.',
                    'details' => $errors,
                ],
            ], 422);
        }

        $actor = $this->getUser();

        if (!$actor instanceof User || $actor->getId() === null) {
            return $this->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Login required.',
                ],
            ], 401);
        }

        try {
            $created = $membershipManager->add(
                $organizationId,
                $actor->getId(),
                $email,
                $role
            );
        } catch (MembershipChangeRejected $exception) {
            return $this->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status);
        }

        return $this->json(['data' => $created], 201);
    }

    #[Route(
        '/organizations/{organizationId}/memberships/{membershipId}',
        name: 'organization_memberships_update',
        requirements: [
            'organizationId' => '\d+',
            'membershipId' => '\d+',
        ],
        methods: ['PATCH']
    )]
    public function update(
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        #[ValueResolver(ResourceIdValueResolver::class)] int $membershipId,
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder,
        MembershipManager $roleChanger
    ): JsonResponse {
        $organization = $em->find(Organization::class, $organizationId);

        if (
            $organization === null
            || !$this->isGranted(OrganizationVoter::VIEW, $organization)
        ) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Organization not found.',
                ],
            ], 404);
        }

        $membership = $em->getRepository(Membership::class)->findOneBy([
            'id' => $membershipId,
            'organization' => $organization,
        ]);

        if ($membership === null) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Membership not found.',
                ],
            ], 404);
        }

        if (!$this->isGranted(OrganizationVoter::MANAGE_MEMBERS, $organization)) {
            return $this->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Only Owners can manage memberships.',
                ],
            ], 403);
        }

        $data = $jsonDecoder->decode($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $errors = [];

        foreach (get_object_vars($data) as $field => $value) {
            if ($field !== 'role') {
                $errors[$field] = 'Unknown field.';
            }
        }

        $roleValue = $data->role ?? null;
        $role = is_string($roleValue)
            ? MembershipRole::tryFrom($roleValue)
            : null;

        if ($role === null) {
            $errors['role'] = 'Must be owner, manager, or viewer.';
        }

        if ($errors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid membership data.',
                    'details' => $errors,
                ],
            ], 422);
        }

        $actor = $this->getUser();

        if (!$actor instanceof User || $actor->getId() === null) {
            return $this->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Login required.',
                ],
            ], 401);
        }

        try {
            $roleChanger->change(
                $organizationId,
                $membershipId,
                $actor->getId(),
                $role
            );
        } catch (MembershipChangeRejected $exception) {
            return $this->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status);
        }

        return $this->json([
            'data' => [
                'id' => $membershipId,
                'role' => $role->value,
            ],
        ]);
    }

    #[Route(
        '/organizations/{organizationId}/memberships/{membershipId}',
        name: 'organization_memberships_remove',
        requirements: [
            'organizationId' => '\d+',
            'membershipId' => '\d+',
        ],
        methods: ['DELETE']
    )]
    public function remove(
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        #[ValueResolver(ResourceIdValueResolver::class)] int $membershipId,
        EntityManagerInterface $em,
        MembershipManager $membershipManager
    ): Response {
        $organization = $em->find(Organization::class, $organizationId);

        if (
            $organization === null
            || !$this->isGranted(OrganizationVoter::VIEW, $organization)
        ) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Organization not found.',
                ],
            ], 404);
        }

        $membership = $em->getRepository(Membership::class)->findOneBy([
            'id' => $membershipId,
            'organization' => $organization,
        ]);

        if ($membership === null) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Membership not found.',
                ],
            ], 404);
        }

        if (!$this->isGranted(OrganizationVoter::MANAGE_MEMBERS, $organization)) {
            return $this->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Only Owners can manage memberships.',
                ],
            ], 403);
        }

        $actor = $this->getUser();

        if (!$actor instanceof User || $actor->getId() === null) {
            return $this->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Login required.',
                ],
            ], 401);
        }

        try {
            $membershipManager->remove(
                $organizationId,
                $membershipId,
                $actor->getId()
            );
        } catch (MembershipChangeRejected $exception) {
            return $this->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}