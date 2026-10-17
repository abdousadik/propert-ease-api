<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Project;
use App\Security\Voter\OrganizationVoter;
use App\Service\DeliveryDateParser;
use App\Service\JsonRequestDecoder;
use App\Service\PaginationParser;
use App\Service\ProjectPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrganizationProjectController extends AbstractController
{
    #[Route(
        '/organizations/{organizationId}/projects',
        name: 'organization_projects_list',
        requirements: ['organizationId' => '\d+'],
        methods: ['GET']
    )]
    public function list(
        int $organizationId,
        Request $request,
        EntityManagerInterface $em,
        PaginationParser $paginationParser,
        DeliveryDateParser $dateParser,
        ProjectPresenter $presenter
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

        $filters = $request->query->all();

        $result = $paginationParser->parse($filters);
        $pagination = $result['values'];
        $paginationErrors = $result['errors'];

        if ($paginationErrors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid pagination parameters.',
                    'details' => $paginationErrors,
                ],
            ], 422);
        }

        $page = $pagination['page'];
        $limit = $pagination['limit'];
        $offset = ($page - 1) * $limit;

        $query = $em->getRepository(Project::class)
            ->createQueryBuilder('p')
            ->where('p.organization = :organization')
            ->andWhere('p.active = :active')
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->orderBy('p.id', 'ASC');

        if (array_key_exists('name', $filters)) {
            $name = $filters['name'];

            if (
                !is_string($name)
                || trim($name) === ''
                || mb_strlen(trim($name), 'UTF-8') > 255
            ) {
                return $this->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => 'Invalid search filters.',
                        'details' => [
                            'name' => 'Must be a nonblank string of at most 255 characters.',
                        ],
                    ],
                ], 422);
            }

            $escapedName = strtr(trim($name), [
                '!' => '!!',
                '%' => '!%',
                '_' => '!_',
            ]);

            $query
                ->andWhere("p.name LIKE :name ESCAPE '!'")
                ->setParameter('name', '%'.$escapedName.'%');
        }

        $dateBounds = [];
        $errors = [];

        foreach (['deliveryDateMin', 'deliveryDateMax'] as $field) {
            if (!array_key_exists($field, $filters)) {
                continue;
            }

            try {
                $date = $dateParser->parse($filters[$field]);

                if ($date === null) {
                    throw new \InvalidArgumentException(
                        'Must be a valid UTC date in YYYY-MM-DD HH:MM:SS format.'
                    );
                }

                $dateBounds[$field] = $date;
            } catch (\InvalidArgumentException $exception) {
                $errors[$field] = $exception->getMessage();
            }
        }

        if (
            isset($dateBounds['deliveryDateMin'], $dateBounds['deliveryDateMax'])
            && $dateBounds['deliveryDateMin'] > $dateBounds['deliveryDateMax']
        ) {
            $errors['deliveryDateMax'] = 'Must be on or after deliveryDateMin.';
        }

        if ($errors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid search filters.',
                    'details' => $errors,
                ],
            ], 422);
        }

        if (isset($dateBounds['deliveryDateMin'])) {
            $query
                ->andWhere('p.deliveryDate >= :deliveryDateMin')
                ->setParameter('deliveryDateMin', $dateBounds['deliveryDateMin']);
        }

        if (isset($dateBounds['deliveryDateMax'])) {
            $query
                ->andWhere('p.deliveryDate <= :deliveryDateMax')
                ->setParameter('deliveryDateMax', $dateBounds['deliveryDateMax']);
        }

        $countQuery = clone $query;

        $total = (int) $countQuery
            ->select('COUNT(p.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $projects = $query
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $data = [];

        foreach ($projects as $project) {
            $data[] = $presenter->present($project);
        }

        return $this->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $total === 0
                    ? 0
                    : intdiv($total - 1, $limit) + 1,
            ],
        ]);
    }

    #[Route(
        '/organizations/{organizationId}/projects/{projectId}',
        name: 'organization_projects_detail',
        requirements: [
            'organizationId' => '\d+',
            'projectId' => '\d+',
        ],
        methods: ['GET']
    )]
    public function detail(
        int $organizationId,
        int $projectId,
        EntityManagerInterface $em,
        ProjectPresenter $presenter
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

        $project = $em->getRepository(Project::class)->findOneBy([
            'id' => $projectId,
            'organization' => $organization,
            'active' => true,
        ]);

        if ($project === null) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Project not found.',
                ],
            ], 404);
        }

        return $this->json([
            'data' => $presenter->present($project),
        ]);
    }

    #[Route(
        '/organizations/{organizationId}/projects/{projectId}',
        name: 'organization_projects_archive',
        requirements: [
            'organizationId' => '\d+',
            'projectId' => '\d+',
        ],
        methods: ['DELETE']
    )]
    public function archive(
        int $organizationId,
        int $projectId,
        EntityManagerInterface $em
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

        $project = $em->getRepository(Project::class)->findOneBy([
            'id' => $projectId,
            'organization' => $organization,
            'active' => true,
        ]);

        if ($project === null) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Project not found.',
                ],
            ], 404);
        }

        if (!$this->isGranted(OrganizationVoter::EDIT, $organization)) {
            return $this->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'You cannot modify projects in this organization.',
                ],
            ], 403);
        }

        $project->setActive(false);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/organizations/{organizationId}/projects',
        name: 'organization_projects_create',
        requirements: ['organizationId' => '\d+'],
        methods: ['POST']
    )]
    public function create(
        int $organizationId,
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder,
        DeliveryDateParser $dateParser
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

        if (!$this->isGranted(OrganizationVoter::EDIT, $organization)) {
            return $this->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'You cannot modify projects in this organization.',
                ],
            ], 403);
        }

        $data = $jsonDecoder->decode($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $errors = [];
        $textFields = [
            'name' => 255,
            'label' => 255,
            'address' => 255,
            'postalCode' => 6,
        ];

        foreach ($textFields as $field => $maximum) {
            $value = $data->$field ?? null;

            if (
                !is_string($value)
                || trim($value) === ''
                || mb_strlen(trim($value), 'UTF-8') > $maximum
            ) {
                $errors[$field] =
                    "Must be a nonblank string of at most {$maximum} characters.";
            }
        }

        $floors = $data->numberOfFloors ?? null;

        if (!is_int($floors) || $floors < 0 || $floors > 2147483647) {
            $errors['numberOfFloors'] =
                'Must be an integer between 0 and 2147483647.';
        }

        $allowedFields = [
            ...array_keys($textFields),
            'numberOfFloors',
            'deliveryDate',
        ];

        foreach (get_object_vars($data) as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $deliveryDate = null;

        if (property_exists($data, 'deliveryDate')) {
            try {
                $deliveryDate = $dateParser->parse($data->deliveryDate);
            } catch (\InvalidArgumentException $exception) {
                $errors['deliveryDate'] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid project data.',
                    'details' => $errors,
                ],
            ], 422);
        }

        $project = (new Project())
            ->setOrganization($organization)
            ->setName(trim($data->name))
            ->setLabel(trim($data->label))
            ->setAddress(trim($data->address))
            ->setPostalCode(trim($data->postalCode))
            ->setNumberOfFloors($floors)
            ->setActive(true)
            ->setDeliveryDate($deliveryDate);

        $em->persist($project);
        $em->flush();

        return $this->json([
            'data' => [
                'id' => $project->getId(),
                'name' => $project->getName(),
            ],
        ], 201);
    }

    #[Route(
        '/organizations/{organizationId}/projects/{projectId}',
        name: 'organization_projects_update',
        requirements: [
            'organizationId' => '\d+',
            'projectId' => '\d+',
        ],
        methods: ['PATCH']
    )]
    public function update(
        int $organizationId,
        int $projectId,
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder,
        DeliveryDateParser $dateParser
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

        $project = $em->getRepository(Project::class)->findOneBy([
            'id' => $projectId,
            'organization' => $organization,
            'active' => true,
        ]);

        if ($project === null) {
            return $this->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Project not found.',
                ],
            ], 404);
        }

        if (!$this->isGranted(OrganizationVoter::EDIT, $organization)) {
            return $this->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'You cannot modify projects in this organization.',
                ],
            ], 403);
        }

        $data = $jsonDecoder->decode($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $errors = [];
        $deliveryDate = null;
        $fields = get_object_vars($data);

        $textFields = [
            'label' => 255,
            'address' => 255,
            'postalCode' => 6,
        ];

        $allowedFields = [...array_keys($textFields), 'numberOfFloors', 'deliveryDate'];

        if ($fields === []) {
            $errors['body'] = 'Provide at least one editable field.';
        }

        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = 'Unknown field.';
                continue;
            }

            if ($field === 'deliveryDate') {
                try {
                    $deliveryDate = $dateParser->parse($value);
                } catch (\InvalidArgumentException $exception) {
                    $errors[$field] = $exception->getMessage();
                }

                continue;
            }

            if (isset($textFields[$field])) {
                $maximum = $textFields[$field];

                if (
                    !is_string($value)
                    || trim($value) === ''
                    || mb_strlen(trim($value), 'UTF-8') > $maximum
                ) {
                    $errors[$field] =
                        "Must be a nonblank string of at most {$maximum} characters.";
                }
            } elseif (
                !is_int($value)
                || $value < 0
                || $value > 2147483647
            ) {
                $errors[$field] =
                    'Must be an integer between 0 and 2147483647.';
            }
        }

        if ($errors !== []) {
            return $this->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid project data.',
                    'details' => $errors,
                ],
            ], 422);
        }

        foreach ($fields as $field => $value) {
            match ($field) {
                'label' => $project->setLabel(trim($value)),
                'address' => $project->setAddress(trim($value)),
                'postalCode' => $project->setPostalCode(trim($value)),
                'numberOfFloors' => $project->setNumberOfFloors($value),
                'deliveryDate' => $project->setDeliveryDate($deliveryDate),
            };
        }
        $em->flush();

        return $this->json([
            'data' => [
                'id' => $project->getId(),
                'label' => $project->getLabel(),
            ],
        ]);
    }
}