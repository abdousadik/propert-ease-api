<?php

namespace App\Tests\Unit;

use App\EventListener\LoginInputExceptionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LoginInputExceptionListenerTest extends TestCase
{
    #[DataProvider('unrelatedExceptions')]
    public function testUnrelatedExceptionsAreNotMasked(string $route, int $requestType, \Throwable $error): void
    {
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class),
            new Request(attributes: ['_route' => $route]), $requestType, $error);
        (new LoginInputExceptionListener())($event);
        self::assertNull($event->getResponse());
    }

    public static function unrelatedExceptions(): iterable
    {
        yield 'other endpoint' => ['signup', HttpKernelInterface::MAIN_REQUEST, new BadRequestHttpException('Private message')];
        yield 'unexpected login failure' => ['api_login_check', HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Private message')];
        yield 'subrequest' => ['api_login_check', HttpKernelInterface::SUB_REQUEST, new BadRequestHttpException('Private message')];
    }
}
