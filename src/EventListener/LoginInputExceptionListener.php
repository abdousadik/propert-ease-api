<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class LoginInputExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (
            !$event->isMainRequest()
            || $event->getRequest()->attributes->get('_route') !== 'api_login_check'
            || !$event->getThrowable() instanceof BadRequestHttpException
        ) {
            return;
        }

        // Never expose the framework exception message or submitted credentials.
        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => 'invalid_login_data',
                'message' => 'Expected a JSON object with string username and password fields.',
            ],
        ], 400));
    }
}
