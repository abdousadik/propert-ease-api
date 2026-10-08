<?php
namespace App\EventSubscriber;

use App\Api\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Security listeners retain Lexik's established authentication responses.
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $code = $exception instanceof ApiProblem ? $exception->problemCode : ($status === 404 ? 'not_found' : 'http_error');
        $message = $exception instanceof ApiProblem ? $exception->getMessage() : (JsonResponse::$statusTexts[$status] ?? 'Request failed');
        $details = $exception instanceof ApiProblem ? $exception->details : [];
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        $event->setResponse(new JsonResponse(['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $status, $headers));
    }
}
