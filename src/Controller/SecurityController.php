<?php
namespace App\Controller;

use App\Api\ApiProblem;
use App\Api\Input;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $passwordHasher) {}

    #[Route('/signup', name: 'signup', methods: ['POST'])]
    public function signup(Request $request): JsonResponse
    {
        $data = Input::signup(Input::json($request));
        if ($this->em->getRepository(User::class)->findOneBy(['email' => $data['email']])) {
            throw new ApiProblem(409, 'email_conflict', 'Email is already registered.');
        }
        $user = (new User())->setFirstName($data['firstName'])->setLastName($data['lastName'])->setPhone($data['phone'])->setEmail($data['email'])->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new ApiProblem(409, 'email_conflict', 'Email is already registered.');
        }
        return $this->json(['data' => ['id' => $user->getId(), 'email' => $user->getEmail(), 'firstName' => $user->getFirstName(), 'lastName' => $user->getLastName(), 'phone' => $user->getPhone()]], 201);
    }
}
