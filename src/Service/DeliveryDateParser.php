<?php

namespace App\Service;

final class DeliveryDateParser
{
    public function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $value,
                new \DateTimeZone('UTC')
            );

            if ($date !== false && $date->format('Y-m-d H:i:s') === $value) {
                return $date;
            }
        }

        throw new \InvalidArgumentException(
            'Must be null or a valid UTC date in YYYY-MM-DD HH:MM:SS format.'
        );
    }
}