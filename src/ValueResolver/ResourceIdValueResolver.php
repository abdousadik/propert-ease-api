<?php

namespace App\ValueResolver;

use App\Exception\InvalidResourceIdException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[AsTargetedValueResolver]
final class ResourceIdValueResolver implements ValueResolverInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if ($argument->getType() !== 'int' || !$request->attributes->has($argument->getName())) {
            return [];
        }

        $raw = $request->attributes->get($argument->getName());
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new InvalidResourceIdException();
        }

        // Compare decimal strings before casting; IDs use signed INT columns.
        $normalized = ltrim($raw, '0');
        if (strlen($normalized) > 10 || (strlen($normalized) === 10 && strcmp($normalized, '2147483647') > 0)) {
            throw new InvalidResourceIdException();
        }

        return [(int) ($normalized === '' ? '0' : $normalized)];
    }
}
