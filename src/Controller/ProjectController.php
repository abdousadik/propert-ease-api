<?php

namespace App\Controller;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectController extends AbstractController
{
    public $em;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
    }

    #[Route('/project', name: 'createProject', methods: ['POST'])]
    public function createProject(Request $request){
        $format = 'Y-m-d H:i:s';

        $project = new Project();

        $name = $request->get('name');
        if (is_null($name) || empty($name)) {
            return new JsonResponse('Name cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $project->setName($name);

        $label = $request->get('label');
        if (is_null($label) || empty($label)) {
            return new JsonResponse('Label cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $project->setLabel($label);

        $numberOfFloors = $request->get('numberOfFloors');
        if ($numberOfFloors === null || !is_numeric($numberOfFloors)) {
            return new JsonResponse(['code' => 400, 'message' => 'NumberOfFloors must be a valid integer.'], Response::HTTP_BAD_REQUEST);
        }
        $project->setNumberOfFloors((int) $numberOfFloors);

        $address = $request->get('address');
        if (is_null($address) || empty($address)) {
            return new JsonResponse('Address cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $project->setAddress($address);

        $postalCode = $request->get('postalCode');
        if (is_null($postalCode) || empty($postalCode)) {
            return new JsonResponse('PostalCode cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $project->setPostalCode($postalCode);

        $deliveryDateString = $request->get('deliveryDate');
        $deliveryDate = \DateTime::createFromFormat($format, $deliveryDateString);
        if (!$deliveryDate) {
            return new JsonResponse('Invalid delivery date', Response::HTTP_BAD_REQUEST);
        }
        $project->setDeliveryDate($deliveryDate);

        if ($request->files->has('picture')) {
            $file = $request->files->get('picture');
            $fileName = md5(uniqid()) . '.' . $file->guessExtension();
            $file->move($this->getParameter('upload_directory'), $fileName);
            $project->setPicture($fileName);
        }

        $project->setActive(true);

        $this->em->persist($project);
        $this->em->flush();
        
        return new JsonResponse(['code' => 200, 'message' => "Project with name '".$request->get('name')."' was created successfully!"], Response::HTTP_OK);
    }

    #[Route('/project/{id}', name: 'deleteProject', methods: ['DELETE'])]
    public function deleteProject($id): Response
    {
        $project = $this->em->getRepository(Project::class)->find($id);

        if (!$project) {
            return $this->json(['message' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }
        
        $project->setActive(false);
        $this->em->persist($project);
        $this->em->flush();

        return $this->json(['message' => 'Project soft-deleted'], Response::HTTP_OK);
    }

    #[Route('/project/{id}', name: 'updateProject', methods: ['PATCH'])]
    public function updateProject(Request $request, int $id): JsonResponse
    {
        $format = 'Y-m-d H:i:s';
        $requestData = json_decode($request->getContent(), true);
        
        if(!$requestData){
            $return = json_encode(['code' => 200, 'message' => 'Project not found']);
            return new JsonResponse($return, Response::HTTP_OK, [], true);
        }

        $project = $this->em->getRepository(Project::class)->findOneBy([
            "id" => $id,
            "active" => true
        ]);
        
        if (!$project) {
            return new JsonResponse('Project not found', Response::HTTP_BAD_REQUEST);
        }

        if (ISSET($requestData['label']) && $requestData['label'] !== null) {
            $project->setLabel($requestData['label']);
        }

        if (ISSET($requestData['numberOfFloors']) && $requestData['numberOfFloors'] !== null) {
            $numberOfFloors = $requestData['numberOfFloors'];
            if ($numberOfFloors === null || !is_numeric($numberOfFloors)) {
                return new JsonResponse(['code' => 400, 'message' => 'numberOfFloors must be a valid integer.'], Response::HTTP_BAD_REQUEST);
            }
            $project->setNumberOfFloors((int) $numberOfFloors);
        }

        if (ISSET($requestData['address']) && $requestData['address'] !== null) {
            $project->setAddress($requestData['address']);
        }

        if (ISSET($requestData['postalCode']) && $requestData['postalCode'] !== null) {
            $project->setPostalCode($requestData['postalCode']);
        }

        if (ISSET($requestData['deliveryDate']) && $requestData['deliveryDate'] !== null) {
            $deliveryDateString = $requestData['deliveryDate'];
            $deliveryDate = \DateTime::createFromFormat($format, $deliveryDateString);
            if (!$deliveryDate) {
                return new JsonResponse('Invalid delivery date', Response::HTTP_BAD_REQUEST);
            }
            $project->setDeliveryDate($deliveryDate);
        }

        $this->em->persist($project);
        $this->em->flush();

        return new JsonResponse(['message' => 'Project updated successfully!'], Response::HTTP_OK);
    }

    #[Route('/project', name: 'getProjects', methods: ['GET'])]
    public function getProjects(Request $request){
        $data = [];
        $projects = $this->em->getRepository(Project::class)->findBy([
            "active" => true
        ]);
        
        if(!$projects){
            $return = json_encode(['code' => 200, 'message' => "NO DATA FOUND :("]);
            return new JsonResponse($return, Response::HTTP_OK, [], true);
        }

        foreach ($projects as $project) {
            $data[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'label' => $project->getLabel(),
                'address' => $project->getAddress(),
                'numberOfFloors' => $project->getNumberOfFloors(),
                'postalCode' => $project->getPostalCode(),
                'deliveryDate' => $project->getDeliveryDate()->format('Y-m-d H:i:s'),
                'picture' => $project->getPicture(),
            ];
        }

        return new JsonResponse($data, Response::HTTP_OK);
    }

    #[Route('/project/search', name: 'searchProject', methods: ['GET'])]
    public function searchProject(Request $request): JsonResponse
    {
        $requestData = json_decode($request->getContent(), true);

        $projects = $this->em->getRepository(Project::class)->searchProjects($requestData);
        if(!$projects){
            $return = json_encode(['code' => 200, 'message' => "NO DATA FOUND :("]);
            return new JsonResponse($return, Response::HTTP_OK, [], true);
        }
        
        foreach ($projects as $project) {
            $data[] = [
                'id' => $project['id'],
                'name' => $project['name'],
                'label' => $project['label'],
                'address' => $project['address'],
                'numberOfFloors' => $project['numberOfFloors'],
                'postalCode' => $project['postalCode'],
                'deliveryDate' => $project['deliveryDate']->format('Y-m-d H:i:s'),
                'picture' => $project['picture'],
            ];
        }

        return new JsonResponse($data, Response::HTTP_OK);
    }

    #[Route('/project/{id}', name: 'getProjectById', methods: ['GET'])]
    public function getProjectById(int $id): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy([
            "id" => $id,
            "active" => true
        ]);

        if (!$project) {
            $return = json_encode(['code' => 200, 'message' => 'Project not found']);
            return new JsonResponse($return, Response::HTTP_OK, [], true);
        }
        
        $data = [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'label' => $project->getLabel(),
            'address' => $project->getAddress(),
            'numberOfFloors' => $project->getNumberOfFloors(),
            'postalCode' => $project->getPostalCode(),
            'deliveryDate' => $project->getDeliveryDate()->format('Y-m-d H:i:s'),
            'picture' => $project->getPicture(),
        ];

        return new JsonResponse($data, Response::HTTP_OK);
    }
}