<?php

require __DIR__ . '/test_bootstrap.php';

use App\Services\Vehicle\VehicleCascadeService;
use App\Services\Vehicle\VehicleMasterRepository;

class ArrayBackedVehicleRepository extends VehicleMasterRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string|int|null>
     */
    public function distinctValues(string $column, array $filters = []): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $matches = true;
            foreach ($filters as $key => $value) {
                if (!array_key_exists($key, $row) || $row[$key] != $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $values[] = $row[$column] ?? null;
            }
        }

        $values = array_values(array_unique($values, SORT_REGULAR));
        if ($column === 'year') {
            rsort($values, SORT_NUMERIC);
        } else {
            sort($values, SORT_NATURAL);
        }

        return $values;
    }
}

$rows = [
    ['year' => 2021, 'make' => 'Ford', 'model' => 'F-150', 'engine' => 'V8', 'transmission' => 'AT', 'drive' => '4WD', 'trim' => 'Lariat'],
    ['year' => 2021, 'make' => 'Ford', 'model' => 'F-150', 'engine' => 'V6', 'transmission' => 'AT', 'drive' => '4WD', 'trim' => 'XL'],
    ['year' => 2022, 'make' => 'Tesla', 'model' => 'Model 3', 'engine' => 'Electric', 'transmission' => '1SPD', 'drive' => 'AWD', 'trim' => null],
];

$service = new VehicleCascadeService(new ArrayBackedVehicleRepository($rows));

$results = [];
$results[] = [
    'scenario' => 'years are returned in descending order',
    'passed' => $service->years() === [2022, 2021],
];
$results[] = [
    'scenario' => 'makes constrained to year',
    'passed' => $service->makes(2021) === ['Ford'],
];
$results[] = [
    'scenario' => 'models constrained to year and make',
    'passed' => $service->models(2021, 'Ford') === ['F-150'],
];
$results[] = [
    'scenario' => 'trim list includes null option for base',
    'passed' => $service->trims(2022, 'Tesla', 'Model 3', 'Electric', '1SPD', 'AWD') === [null],
];

try {
    $service->makes(1890);
    $results[] = ['scenario' => 'invalid year triggers exception', 'passed' => false];
} catch (InvalidArgumentException $e) {
    $results[] = ['scenario' => 'invalid year triggers exception', 'passed' => true];
}

$failures = array_filter($results, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All vehicle cascade service tests passed." . PHP_EOL;
