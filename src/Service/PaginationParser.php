<?php

namespace App\Service;

final class PaginationParser
{
    /**
     * @return array{
     *     values: array{page?: int, limit?: int},
     *     errors: array<string, string>
     * }
     */
    public function parse(array $filters): array
    {
        $pagination = [];
        $paginationErrors = [];

        foreach (['page' => 1, 'limit' => 20] as $field => $default) {
            $raw = $filters[$field] ?? (string) $default;

            if (
                !is_string($raw)
                || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1
            ) {
                $paginationErrors[$field] = 'Must be a positive decimal integer.';
                continue;
            }

            $value = filter_var($raw, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => $field === 'limit' ? 100 : 2147483647,
                ],
            ]);

            if ($value === false) {
                $paginationErrors[$field] = $field === 'limit'
                    ? 'Must be between 1 and 100.'
                    : 'Page is too large.';
                continue;
            }

            $pagination[$field] = $value;
        }

        if (
            $paginationErrors === []
            && $pagination['page'] - 1 > intdiv(2147483647, $pagination['limit'])
        ) {
            $paginationErrors['page'] = 'Page offset is too large.';
        }

        return [
            'values' => $pagination,
            'errors' => $paginationErrors,
        ];
    }
}