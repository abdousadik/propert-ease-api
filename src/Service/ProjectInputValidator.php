<?php

namespace App\Service;

final class ProjectInputValidator
{
    private const TEXT_FIELDS = [
        'name' => 255,
        'label' => 255,
        'address' => 255,
        'postalCode' => 6,
    ];

    public function __construct(private readonly DeliveryDateParser $dateParser)
    {
    }

    /**
     * @return array{errors: array<string, string>, deliveryDate: ?\DateTimeImmutable}
     */
    public function validateCreate(\stdClass $data): array
    {
        $errors = [];

        foreach (self::TEXT_FIELDS as $field => $maximum) {
            $error = $this->textError($data->$field ?? null, $maximum);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        $error = $this->floorsError($data->numberOfFloors ?? null);
        if ($error !== null) {
            $errors['numberOfFloors'] = $error;
        }

        $allowedFields = [...array_keys(self::TEXT_FIELDS), 'numberOfFloors', 'deliveryDate'];
        foreach (get_object_vars($data) as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $deliveryDate = null;
        if (property_exists($data, 'deliveryDate')) {
            try {
                $deliveryDate = $this->dateParser->parse($data->deliveryDate);
            } catch (\InvalidArgumentException $exception) {
                $errors['deliveryDate'] = $exception->getMessage();
            }
        }

        return ['errors' => $errors, 'deliveryDate' => $deliveryDate];
    }

    /**
     * @return array{errors: array<string, string>, deliveryDate: ?\DateTimeImmutable}
     */
    public function validateUpdate(\stdClass $data): array
    {
        $errors = [];
        $deliveryDate = null;
        $fields = get_object_vars($data);
        $textFields = self::TEXT_FIELDS;
        unset($textFields['name']);
        $allowedFields = [...array_keys($textFields), 'numberOfFloors', 'deliveryDate'];

        if ($fields === []) {
            $errors['body'] = 'Provide at least one editable field.';
        }

        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = 'Unknown field.';
                continue;
            }

            if ($field === 'deliveryDate') {
                try {
                    $deliveryDate = $this->dateParser->parse($value);
                } catch (\InvalidArgumentException $exception) {
                    $errors[$field] = $exception->getMessage();
                }
                continue;
            }

            $error = isset($textFields[$field])
                ? $this->textError($value, $textFields[$field])
                : $this->floorsError($value);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return ['errors' => $errors, 'deliveryDate' => $deliveryDate];
    }

    private function textError(mixed $value, int $maximum): ?string
    {
        if (
            !is_string($value)
            || trim($value) === ''
            || mb_strlen(trim($value), 'UTF-8') > $maximum
        ) {
            return "Must be a nonblank string of at most {$maximum} characters.";
        }

        return null;
    }

    private function floorsError(mixed $value): ?string
    {
        if (!is_int($value) || $value < 0 || $value > 2147483647) {
            return 'Must be an integer between 0 and 2147483647.';
        }

        return null;
    }
}
