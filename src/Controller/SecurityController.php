<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityController extends AbstractController
{
    public $em;
    public $passwordHasher;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher) {
        $this->em = $em;
        $this->passwordHasher = $passwordHasher;
    }
    
    #[Route('/signup', name: 'signup', methods: ['POST'])]
    public function signup(Request $request){
        $user = new User();

        $firstName = $request->get('firstName');
        if (is_null($firstName) || empty($firstName)) {
            return new JsonResponse('FirstName cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setFirstName($firstName);

        $lastName = $request->get('lastName');
        if (is_null($lastName) || empty($lastName)) {
            return new JsonResponse('LastName cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setLastName($lastName);

        $phone = $request->get('phone');
        if (is_null($phone) || empty($phone)) {
            return new JsonResponse('Phone cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setPhone($phone);

        $email = $request->get('email');
        if (is_null($email) || empty($email)) {
            return new JsonResponse('Email cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setEmail($email);

        $password = $request->get('password');
        if (is_null($password) || empty($password)) {
            return new JsonResponse('Password cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $user->setRoles(['USER']);

        $this->em->persist($user);
        $this->em->flush();
        
        return new JsonResponse(['code' => 200, 'message' => "User with email '".$request->get('email')."' was created successfully!"], Response::HTTP_OK);
    }
}