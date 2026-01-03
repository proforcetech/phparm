<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\VehicleMaster;
use App\Services\Vehicle\VehicleMasterImporter;
use App\Services\Vehicle\VehicleMasterRepository;
use App\Services\Vehicle\VehicleMasterValidator;

class FakeVehicleMasterRepository extends VehicleMasterRepository
{
    private VehicleMasterValidator $validator;

    /** @var array<int, VehicleMaster> */
    private array $store = [];

    private int $nextId = 1;

    public function __construct()
    {
        $this->validator = new VehicleMasterValidator();
    }

    public function create(array $data): VehicleMaster
    {
        $payload = $this->validator->validate($data);
        $vehicle = new VehicleMaster(array_merge($payload, ['id' => $this->nextId++]));
        $this->store[$vehicle->id] = $vehicle;

        return $vehicle;
    }

    public function update(int $id, array $data): ?VehicleMaster
    {
        if (!isset($this->store[$id])) {
            return null;
        }

        $payload = $this->validator->validate(array_merge($this->store[$id]->toArray(), $data));
        $vehicle = new VehicleMaster(array_merge($payload, ['id' => $id]));
        $this->store[$id] = $vehicle;

        return $vehicle;
    }

    public function findByAttributes(array $payload): ?VehicleMaster
    {
        foreach ($this->store as $vehicle) {
            if ($this->matches($vehicle, $payload)) {
                return $vehicle;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, VehicleMaster>
     */
    public function search(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        return array_values($this->store);
    }

    private function matches(VehicleMaster $vehicle, array $payload): bool
    {
        return (int) $vehicle->year === (int) $payload['year']
            && $vehicle->make === $payload['make']
            && $vehicle->model === $payload['model']
            && $vehicle->engine === $payload['engine']
            && $vehicle->transmission === $payload['transmission']
            && $vehicle->drive === $payload['drive']
            && ($vehicle->trim ?? null) === ($payload['trim'] ?? null);
    }
}

$repo = new FakeVehicleMasterRepository();
$importer = new VehicleMasterImporter($repo);

$csv = <<<CSV
year,make,model,engine,transmission,drive,trim
2020,Ford,F-150,V8,AT,4WD,Lariat
2020,Ford,F-150,V8,AT,4WD,Lariat
2021,Honda,Civic,I4,CVT,FWD,
1950,Ford,,V8,AT,4WD,
CSV;

$mapping = [
    'year' => 'year',
    'make' => 'make',
    'model' => 'model',
    'engine' => 'engine',
    'transmission' => 'transmission',
    'drive' => 'drive',
    'trim' => 'trim',
];

$preview = $importer->preview($csv, $mapping);
$results = [];
$results[] = [
    'scenario' => 'preview counts valid rows',
    'passed' => count($preview['preview']) === 3,
];
$duplicateFlag = $preview['preview'][1]['duplicate'] ?? false;
$results[] = [
    'scenario' => 'duplicate row flagged in preview',
    'passed' => $duplicateFlag === true,
];
$results[] = [
    'scenario' => 'preview captured validation error',
    'passed' => count($preview['errors']) === 1,
];

$summary = $importer->import($csv, $mapping, true);
$results[] = [
    'scenario' => 'created rows counted',
    'passed' => $summary['created'] === 2,
];
$results[] = [
    'scenario' => 'duplicate row updated when allowed',
    'passed' => $summary['updated'] === 1 && $summary['duplicates'] === 1,
];
$results[] = [
    'scenario' => 'validation failures tracked',
    'passed' => $summary['failed'] === 1 && count($summary['errors']) === 1,
];

$failures = array_filter($results, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All vehicle master importer tests passed." . PHP_EOL;
