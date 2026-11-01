<?php

namespace App\Controller;

use App\ValueResolver\ResourceIdValueResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

use App\Entity\Organization;
use App\Entity\Project;
use App\Security\Voter\OrganizationVoter;
use App\Service\DeliveryDateParser;
use App\Service\JsonRequestDecoder;
use App\Service\PaginationParser;
use App\Service\ProjectPresenter;
use App\Service\ProjectInputValidator;
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
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
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
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        #[ValueResolver(ResourceIdValueResolver::class)] int $projectId,
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
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        #[ValueResolver(ResourceIdValueResolver::class)] int $projectId,
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
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder,
        ProjectInputValidator $validator
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

        $result = $validator->validateCreate($data);

        if ($result['errors'] !== []) {
            return $this->projectValidationError($result['errors']);
        }

        $project = (new Project())
            ->setOrganization($organization)
            ->setName(trim($data->name))
            ->setLabel(trim($data->label))
            ->setAddress(trim($data->address))
            ->setPostalCode(trim($data->postalCode))
            ->setNumberOfFloors($data->numberOfFloors)
            ->setActive(true)
            ->setDeliveryDate($result['deliveryDate']);

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
        #[ValueResolver(ResourceIdValueResolver::class)] int $organizationId,
        #[ValueResolver(ResourceIdValueResolver::class)] int $projectId,
        Request $request,
        EntityManagerInterface $em,
        JsonRequestDecoder $jsonDecoder,
        ProjectInputValidator $validator
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

        $result = $validator->validateUpdate($data);

        if ($result['errors'] !== []) {
            return $this->projectValidationError($result['errors']);
        }

        $fields = get_object_vars($data);
        foreach ($fields as $field => $value) {
            match ($field) {
                'label' => $project->setLabel(trim($value)),
                'address' => $project->setAddress(trim($value)),
                'postalCode' => $project->setPostalCode(trim($value)),
                'numberOfFloors' => $project->setNumberOfFloors($value),
                'deliveryDate' => $project->setDeliveryDate($result['deliveryDate']),
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

    /** @param array<string, string> $errors */
    private function projectValidationError(array $errors): JsonResponse
    {
        return $this->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Invalid project data.',
                'details' => $errors,
            ],
        ], 422);
    }
}
