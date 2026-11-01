<?php

namespace App\Tests\Unit;

use App\Exception\InvalidResourceIdException;
use App\ValueResolver\ResourceIdValueResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class ResourceIdValueResolverTest extends TestCase
{
    #[DataProvider('identifiers')]
    public function testDatabaseIntegerBoundsAndDecimalNormalization(string $raw, ?int $expected): void
    {
        $resolver = new ResourceIdValueResolver();
        $request = new Request(attributes: ['organizationId' => $raw]);
        $argument = new ArgumentMetadata('organizationId', 'int', false, false, null);
        if ($expected === null) {
            $this->expectException(InvalidResourceIdException::class);
        }
        self::assertSame([$expected], $resolver->resolve($request, $argument));
    }

    public static function identifiers(): iterable
    {
        yield 'zero retains missing-resource handling' => ['0', 0];
        yield 'valid' => ['1', 1];
        yield 'signed INT maximum' => ['2147483647', 2147483647];
        yield 'leading zeros' => [str_repeat('0', 30).'1', 1];
        yield 'signed INT overflow' => ['2147483648', null];
        yield 'PHP integer overflow' => ['9223372036854775808', null];
        yield 'large decimal' => [str_repeat('9', 100), null];
        yield 'scientific notation' => ['1e2', null];
        yield 'negative' => ['-1', null];
    }
}
