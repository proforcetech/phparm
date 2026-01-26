<?php

namespace App\Models;

use ReflectionClass;
use ReflectionNamedType;

abstract class BaseModel
{
    public function __construct(array $attributes = [])
    {
        $reflection = new ReflectionClass($this);

        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $this->castValue($reflection, $key, $value);
            }
        }
    }

    /**
     * Cast a value to the appropriate type based on the property's type declaration.
     *
     * @param ReflectionClass<static> $reflection
     */
    private function castValue(ReflectionClass $reflection, string $key, mixed $value): mixed
    {
        // Null values pass through unchanged
        if ($value === null) {
            return null;
        }

        try {
            $property = $reflection->getProperty($key);
            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType) {
                return $value;
            }

            $typeName = $type->getName();

            // Handle array properties - decode JSON strings
            if ($typeName === 'array' && is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            }

            // Handle boolean properties - cast from int/string
            if ($typeName === 'bool' && !is_bool($value)) {
                return (bool) $value;
            }

            // Handle int properties - cast from string
            if ($typeName === 'int' && is_string($value) && is_numeric($value)) {
                return (int) $value;
            }

            // Handle float properties - cast from string
            if ($typeName === 'float' && is_string($value) && is_numeric($value)) {
                return (float) $value;
            }

            return $value;
        } catch (\ReflectionException $e) {
            return $value;
        }
    }

    public function toArray(): array
    {
        $data = [];
        foreach (get_object_vars($this) as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }
}
