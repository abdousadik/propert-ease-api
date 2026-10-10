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

        $contentType = strtolower(trim(
            explode(';', $request->headers->get('Content-Type', ''), 2)[0]
        ));

        if ($contentType !== 'application/json') {
            return new JsonResponse(
                [
                    'error' => [
                        'code' => 'unsupported_media_type',
                        'message' => 'Content-Type must be application/json.',
                    ],
                ],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE
            );
        }

        try {
            $data = json_decode(
                $request->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            return new JsonResponse(
                ['error' => ['code' => 'invalid_json', 'message' => 'Malformed JSON body.']],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$data instanceof \stdClass) {
            return new JsonResponse(
                ['error' => ['code' => 'invalid_body', 'message' => 'Expected a JSON object.']],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (isset($data->email) && is_string($data->email)) {
            $data->email = strtolower(trim($data->email));
        }

        $errors = [];

        $maxLengths = [
            'firstName' => 100,
            'lastName' => 100,
            'phone' => 20,
            'email' => 180,
        ];

        foreach (['firstName', 'lastName', 'phone', 'email', 'password'] as $field) {
            if (!property_exists($data, $field)) {
                $errors[$field] = 'Required.';
            } elseif (!is_string($data->$field)) {
                $errors[$field] = 'Must be a string.';
            } elseif ($field !== 'password' && trim($data->$field) === '') {
                $errors[$field] = 'Must not be blank.';
            } elseif (
                isset($maxLengths[$field])
                && mb_strlen($data->$field, 'UTF-8') > $maxLengths[$field]
            ) {
                $errors[$field] = "Must be at most {$maxLengths[$field]} characters.";
            }
        }

        if (!isset($errors['password'])) {
            $passwordLength = mb_strlen($data->password, 'UTF-8');

            if ($passwordLength < 12) {
                $errors['password'] = 'Must be at least 12 characters.';
            } elseif ($passwordLength > 128) {
                $errors['password'] = 'Must be at most 128 characters.';
            }
        }

        if (
            !isset($errors['email'])
            && filter_var($data->email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'Must be a valid email address.';
        }

        if ($errors !== []) {
            return new JsonResponse(
                [
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => 'Invalid signup data.',
                        'details' => $errors,
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = new User();

        $firstName = $data->firstName ?? null;
        if (is_null($firstName) || empty($firstName)) {
            return new JsonResponse('FirstName cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setFirstName($firstName);

        $lastName = $data->lastName ?? null;
        if (is_null($lastName) || empty($lastName)) {
            return new JsonResponse('LastName cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setLastName($lastName);

        $phone = $data->phone ?? null;
        if (is_null($phone) || empty($phone)) {
            return new JsonResponse('Phone cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $user->setPhone($phone);

        $email = $data->email;
        if (is_null($email) || empty($email)) {
            return new JsonResponse('Email cannot be blank', Response::HTTP_BAD_REQUEST);
        }

        $found = $this->em->getRepository(User::class)->findOneBy([
            "email" => $email
        ]);
        if ($found) {
            return new JsonResponse(
                [
                    'error' => [
                        'code' => 'email_already_used',
                        'message' => 'Email already used.',
                    ],
                ],
                Response::HTTP_CONFLICT
            );
        }

        $user->setEmail($email);

        $password = $data->password ?? null;
        if (is_null($password) || empty($password)) {
            return new JsonResponse('Password cannot be blank', Response::HTTP_BAD_REQUEST);
        }
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $user->setRoles(['USER']);

        $this->em->persist($user);
        $this->em->flush();
        
        return new JsonResponse(
            [
                'data' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'phone' => $user->getPhone(),
                ],
            ],
            Response::HTTP_CREATED
        );
    }
}