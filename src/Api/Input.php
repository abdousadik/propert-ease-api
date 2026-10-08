<?php
namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

final class Input
{
    public static function json(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'json') {
            throw new ApiProblem(415, 'unsupported_media_type', 'Use application/json.');
        }
        try {
            $object = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiProblem(400, 'invalid_json', 'Provide a valid JSON object.');
        }
        if (!$object instanceof \stdClass) {
            throw new ApiProblem(400, 'invalid_json', 'Provide a JSON object.');
        }
        return (array) $object;
    }

    public static function signup(array $data): array
    {
        $limits = ['firstName' => 100, 'lastName' => 100, 'phone' => 20, 'email' => 180, 'password' => 128];
        $errors = self::unknown($data, array_keys($limits));
        foreach ($limits as $field => $max) {
            $value = $data[$field] ?? null;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === '' || mb_strlen($value) > $max) {
                $errors[$field] = "Must be a nonblank string of at most $max characters.";
            } elseif ($field !== 'password') {
                $data[$field] = trim($value);
            }
        }
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Must be a valid email address.';
            }
        }
        if (isset($data['password']) && is_string($data['password']) && mb_strlen($data['password']) < 12) {
            $errors['password'] = 'Must contain 12 to 128 characters.';
        }
        self::validate($errors);
        return $data;
    }

    public static function project(array $data, bool $patch, bool $multipart): array
    {
        if ($patch && !$data) {
            throw new ApiProblem(400, 'empty_patch', 'Provide at least one mutable field.');
        }
        $limits = ['label' => 255, 'address' => 255, 'postalCode' => 6];
        if (!$patch) {
            $limits['name'] = 255;
        }
        $allowed = [...array_keys($limits), 'numberOfFloors', 'deliveryDate'];
        $errors = self::unknown($data, $allowed);
        foreach ($limits as $field => $max) {
            if ($patch && !array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field] ?? null;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === '' || mb_strlen($value) > $max) {
                $errors[$field] = "Must be a nonblank string of at most $max characters.";
            } else {
                $data[$field] = trim($value);
            }
        }
        if (!$patch || array_key_exists('numberOfFloors', $data)) {
            $value = $data['numberOfFloors'] ?? null;
            if ($multipart && is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
                $canonical = ltrim($value, '0') ?: '0';
                $value = strlen($canonical) <= 10 && (strlen($canonical) < 10 || strcmp($canonical, '2147483647') <= 0) ? (int) $canonical : null;
            }
            if (!is_int($value) || $value < 0 || $value > 2147483647) {
                $errors['numberOfFloors'] = 'Must be an integer from 0 to 2147483647.';
            } else {
                $data['numberOfFloors'] = $value;
            }
        }
        if (!$patch || array_key_exists('deliveryDate', $data)) {
            $date = self::date($data['deliveryDate'] ?? null);
            if ($date === null) {
                $errors['deliveryDate'] = 'Use a valid date in Y-m-d H:i:s format.';
            } else {
                $data['deliveryDate'] = $date;
            }
        }
        self::validate($errors);
        return $data;
    }

    public static function query(Request $request, bool $search): array
    {
        $data = $request->query->all();
        $errors = self::unknown($data, $search ? ['page', 'limit', 'name', 'deliveryDateMin', 'deliveryDateMax'] : ['page', 'limit']);
        foreach (['page' => [1, 2147483647], 'limit' => [20, 100]] as $key => [$default, $max]) {
            $value = $data[$key] ?? (string) $default;
            if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > 10 || (int) $value > $max) {
                $errors[$key] = "Must be an integer from 1 to $max.";
            } else {
                $data[$key] = (int) $value;
            }
        }
        if (!$errors && ($data['page'] - 1) * $data['limit'] > 2147483647) {
            $errors['page'] = 'Requested offset is too large.';
        }
        if (array_key_exists('name', $data)) {
            if (!is_string($data['name']) || !mb_check_encoding($data['name'], 'UTF-8') || trim($data['name']) === '' || mb_strlen($data['name']) > 255) {
                $errors['name'] = 'Must be a nonblank UTF-8 string of at most 255 characters.';
            } else {
                $data['name'] = trim($data['name']);
            }
        }
        foreach (['deliveryDateMin', 'deliveryDateMax'] as $key) {
            if (array_key_exists($key, $data)) {
                $date = self::date($data[$key]);
                if ($date === null) {
                    $errors[$key] = 'Use a valid date in Y-m-d H:i:s format.';
                } else {
                    $data[$key] = $date;
                }
            }
        }
        if (!$errors && isset($data['deliveryDateMin'], $data['deliveryDateMax']) && $data['deliveryDateMin'] > $data['deliveryDateMax']) {
            $errors['deliveryDateMax'] = 'Must not precede deliveryDateMin.';
        }
        self::validate($errors, 400);
        return $data;
    }

    private static function date(mixed $value): ?\DateTime
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
            return null;
        }
        // MySQL DATETIME range; use UTC to avoid environment-specific DST normalization.
        if (substr($value, 0, 4) < '1000') {
            return null;
        }
        $date = \DateTime::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }

    private static function unknown(array $data, array $allowed): array
    {
        $errors = [];
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $safeKey = mb_check_encoding((string) $key, 'UTF-8') ? $key : 'invalid_field_name';
            $errors[$safeKey] = 'Unknown or immutable field.';
        }
        return $errors;
    }

    private static function validate(array $errors, int $status = 422): void
    {
        if ($errors) {
            throw new ApiProblem($status, 'validation_failed', 'Check the supplied fields.', $errors);
        }
    }
}
