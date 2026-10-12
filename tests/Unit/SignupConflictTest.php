<?php

namespace App\Tests\Unit;

use App\Controller\SecurityController;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class SignupConflictTest extends TestCase
{
    #[DataProvider('persistenceFailureTypes')]
    public function testSignupHandlesPersistenceFailures(bool $duplicate): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'demo@example.com'])
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $em->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(User::class));

        $exception = $duplicate
            ? $this->createMock(UniqueConstraintViolationException::class)
            : new \RuntimeException('Unexpected persistence failure.');

        $em->expects(self::once())
            ->method('flush')
            ->willThrowException($exception);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(
                self::isInstanceOf(User::class),
                'LocalDemoPassword123!'
            )
            ->willReturn('test-password-hash');

        $controller = new SecurityController($em, $hasher);

        $request = Request::create(
            '/api/signup',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'firstName' => 'Demo',
                'lastName' => 'User',
                'phone' => '0600000000',
                'email' => 'demo@example.com',
                'password' => 'LocalDemoPassword123!',
            ], JSON_THROW_ON_ERROR)
        );

        if (!$duplicate) {
            $this->expectExceptionObject($exception);
        }

        $response = $controller->signup($request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'error' => [
                'code' => 'email_already_used',
                'message' => 'Email already used.',
            ],
        ], json_decode(
            $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        ));
    }

    public static function persistenceFailureTypes(): iterable
    {
        yield 'duplicate email' => [true];
        yield 'unexpected failure' => [false];
    }
}