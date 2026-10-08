<?php

namespace App\Controller;

use App\Api\ApiProblem;
use App\Api\Input;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Service\ProjectPictureStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ProjectRepository $projects, private readonly ProjectPictureStorage $pictures) {}

    #[Route('/project', name: 'createProject', methods: ['POST'])]
    public function createProject(Request $request): JsonResponse
    {
        $multipart = str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'multipart/form-data');
        $data = Input::project($multipart ? $request->request->all() : Input::json($request), false, $multipart);
        $project = (new Project())->setOwner($this->owner())->setActive(true);
        $this->apply($project, $data);
        $files = $request->files->all();
        if (array_diff(array_keys($files), ['picture']) || (isset($files['picture']) && !$files['picture'] instanceof UploadedFile)) {
            throw new ApiProblem(422, 'validation_failed', 'Provide one picture file only.', ['picture' => 'Unknown file field or nested upload.']);
        }
        $filename = isset($files['picture']) ? $this->pictures->store($files['picture']) : null;
        $project->setPicture($filename);
        try {
            $this->em->persist($project);
            $this->em->flush();
        } catch (\Throwable $e) {
            if ($filename !== null) {
                $this->pictures->remove($filename);
            }
            throw $e;
        }
        return $this->json(['data' => $this->resource($project)], 201, ['Location' => $this->generateUrl('getProjectById', ['id' => $project->getId()])]);
    }

    #[Route('/project/{id}', name: 'updateProject', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['PATCH'])]
    public function updateProject(Request $request, string $id): JsonResponse
    {
        $project = $this->find($id);
        $data = Input::project(Input::json($request), true, false);
        $this->apply($project, $data);
        $this->em->flush();
        return $this->json(['data' => $this->resource($project)]);
    }

    #[Route('/project/{id}', name: 'deleteProject', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['DELETE'])]
    public function deleteProject(string $id): Response
    {
        $project = $this->find($id);
        $project->setActive(false);
        $this->em->flush();
        return new Response('', 204);
    }

    #[Route('/project', name: 'getProjects', methods: ['GET'])]
    public function getProjects(Request $request): JsonResponse
    {
        return $this->collection($request, false);
    }

    #[Route('/project/search', name: 'searchProject', methods: ['GET'])]
    public function searchProject(Request $request): JsonResponse
    {
        return $this->collection($request, true);
    }

    #[Route('/project/{id}', name: 'getProjectById', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET'])]
    public function getProjectById(string $id): JsonResponse
    {
        return $this->json(['data' => $this->resource($this->find($id))]);
    }

    private function collection(Request $request, bool $search): JsonResponse
    {
        $filters = Input::query($request, $search);
        $result = $this->projects->searchOwned($this->owner(), $filters, $filters['page'], $filters['limit']);
        return $this->json(['data' => array_map($this->resource(...), $result['items']), 'meta' => ['page' => $filters['page'], 'limit' => $filters['limit'], 'total' => $result['total']]]);
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new ApiProblem(401, 'unauthorized', 'Authentication required.');
        }
        return $user;
    }

    private function find(string $id): Project
    {
        $project = (int) $id <= 2147483647 ? $this->projects->findActiveOwned((int) $id, $this->owner()) : null;
        if (!$project) {
            throw new ApiProblem(404, 'not_found', 'Project not found.');
        }
        return $project;
    }

    private function apply(Project $project, array $data): void
    {
        foreach ($data as $field => $value) {
            $project->{'set'.ucfirst($field)}($value);
        }
    }

    private function resource(Project $project): array
    {
        return ['id' => $project->getId(), 'name' => $project->getName(), 'label' => $project->getLabel(), 'numberOfFloors' => $project->getNumberOfFloors(), 'address' => $project->getAddress(), 'postalCode' => $project->getPostalCode(), 'deliveryDate' => $project->getDeliveryDate()?->format('Y-m-d H:i:s'), 'picture' => $project->getPicture()];
    }
}
