<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class JsonRequestDecoder
{
    public function decode(Request $request): \stdClass|JsonResponse
    {
        $contentType = strtolower(trim(
            explode(';', $request->headers->get('Content-Type', ''), 2)[0]
        ));

        if ($contentType !== 'application/json') {
            return new JsonResponse([
                'error' => [
                    'code' => 'unsupported_media_type',
                    'message' => 'Content-Type must be application/json.',
                ],
            ], 415);
        }

        try {
            $data = json_decode(
                $request->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'invalid_json', 'message' => 'Malformed JSON body.']],
                400
            );
        }

        if (!$data instanceof \stdClass) {
            return new JsonResponse(
                ['error' => ['code' => 'invalid_body', 'message' => 'Expected a JSON object.']],
                400
            );
        }

        return $data;
    }
}