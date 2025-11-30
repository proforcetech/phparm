<?php

namespace App\Services\Vehicle;

use InvalidArgumentException;

class VehicleCascadeService
{
    private VehicleMasterRepository $repository;

    public function __construct(VehicleMasterRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<int, int>
     */
    public function years(): array
    {
        return array_map('intval', $this->repository->distinctValues('year'));
    }

    /**
     * @return array<int, string>
     */
    public function makes(int $year): array
    {
        $this->assertYear($year);

        return $this->stringDistinct('make', ['year' => $year]);
    }

    /**
     * @return array<int, string>
     */
    public function models(int $year, string $make): array
    {
        $this->assertYear($year);
        $this->assertNonEmpty($make, 'make');

        return $this->stringDistinct('model', ['year' => $year, 'make' => $make]);
    }

    /**
     * @return array<int, string>
     */
    public function engines(int $year, string $make, string $model): array
    {
        $this->assertYear($year);
        $this->assertNonEmpty($make, 'make');
        $this->assertNonEmpty($model, 'model');

        return $this->stringDistinct('engine', [
            'year' => $year,
            'make' => $make,
            'model' => $model,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function transmissions(int $year, string $make, string $model, string $engine): array
    {
        $this->assertYear($year);
        $this->assertNonEmpty($make, 'make');
        $this->assertNonEmpty($model, 'model');
        $this->assertNonEmpty($engine, 'engine');

        return $this->stringDistinct('transmission', [
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function drives(int $year, string $make, string $model, string $engine, string $transmission): array
    {
        $this->assertYear($year);
        $this->assertNonEmpty($make, 'make');
        $this->assertNonEmpty($model, 'model');
        $this->assertNonEmpty($engine, 'engine');
        $this->assertNonEmpty($transmission, 'transmission');

        return $this->stringDistinct('drive', [
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
            'transmission' => $transmission,
        ]);
    }

    /**
     * @return array<int, string|null>
     */
    public function trims(int $year, string $make, string $model, string $engine, string $transmission, string $drive): array
    {
        $this->assertYear($year);
        $this->assertNonEmpty($make, 'make');
        $this->assertNonEmpty($model, 'model');
        $this->assertNonEmpty($engine, 'engine');
        $this->assertNonEmpty($transmission, 'transmission');
        $this->assertNonEmpty($drive, 'drive');

        return $this->repository->distinctValues('trim', [
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
            'transmission' => $transmission,
            'drive' => $drive,
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private function stringDistinct(string $column, array $filters = []): array
    {
        return array_values(array_map('strval', $this->repository->distinctValues($column, $filters)));
    }

    private function assertYear(int $year): void
    {
        if ($year < 1900 || $year > (int) date('Y') + 1) {
            throw new InvalidArgumentException('Provide a valid model year to query dropdown options.');
        }
    }

    private function assertNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(ucfirst($field) . ' must be provided to query dependent options.');
        }
    }
}
