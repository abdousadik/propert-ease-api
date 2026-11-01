<?php

namespace App\EventListener;

use App\Exception\InvalidResourceIdException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class InvalidResourceIdListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof InvalidResourceIdException) {
            $event->setResponse(new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => 'Resource not found.'],
            ], 404));
        }
    }
}
