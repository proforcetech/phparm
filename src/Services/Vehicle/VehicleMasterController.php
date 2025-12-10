<?php

namespace App\Services\Vehicle;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class VehicleMasterController
{
    private VehicleMasterRepository $repository;
    private AccessGate $gate;
    private VehicleMasterImporter $importer;
    private VehicleCascadeService $cascade;
    private ?VinDecoderService $vinDecoder;
    private ?VehicleNormalizationJob $normalizationJob;

    public function __construct(
        VehicleMasterRepository $repository,
        AccessGate $gate,
        ?VehicleMasterImporter $importer = null,
        ?VehicleCascadeService $cascade = null,
        ?VinDecoderService $vinDecoder = null,
        ?VehicleNormalizationJob $normalizationJob = null
    )
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->importer = $importer ?? new VehicleMasterImporter($repository);
        $this->cascade = $cascade ?? new VehicleCascadeService($repository);
        $this->vinDecoder = $vinDecoder;
        $this->normalizationJob = $normalizationJob;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        $filters = $this->extractFilters($params);
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 25;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($item) => $item->toArray(), $this->repository->search($filters, $limit, $offset));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->repository->find($id)?->toArray();
    }

    /**
     * @return array<int, int>
     */
    public function years(User $user): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->years();
    }

    /**
     * @return array<int, string>
     */
    public function makes(User $user, int $year): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->makes($year);
    }

    /**
     * @return array<int, string>
     */
    public function models(User $user, int $year, string $make): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->models($year, $make);
    }

    /**
     * @return array<int, string>
     */
    public function engines(User $user, int $year, string $make, string $model): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->engines($year, $make, $model);
    }

    /**
     * @return array<int, string>
     */
    public function transmissions(User $user, int $year, string $make, string $model, string $engine): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->transmissions($year, $make, $model, $engine);
    }

    /**
     * @return array<int, string>
     */
    public function drives(User $user, int $year, string $make, string $model, string $engine, string $transmission): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->drives($year, $make, $model, $engine, $transmission);
    }

    /**
     * @return array<int, string|null>
     */
    public function trims(
        User $user,
        int $year,
        string $make,
        string $model,
        string $engine,
        string $transmission,
        string $drive
    ): array {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->trims($year, $make, $model, $engine, $transmission, $drive);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function autocomplete(User $user, array $params = []): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        $term = isset($params['term']) ? trim((string) $params['term']) : '';
        if ($term === '') {
            throw new InvalidArgumentException('Provide a search term for autocomplete.');
        }

        $results = $this->repository->search(['term' => $term], $params['limit'] ?? 10);

        return array_map(static function ($vehicle) {
            $payload = $vehicle->toArray();
            $payload['label'] = sprintf(
                '%s %s %s %s',
                $payload['year'],
                $payload['make'],
                $payload['model'],
                $payload['engine']
            );

            return $payload;
        }, $results);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.create');

        $vehicle = $this->repository->create($data);

        return $vehicle->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.update');

        $vehicle = $this->repository->update($id, $data);

        return $vehicle?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.delete');

        return $this->repository->delete($id);
    }

    /**
     * Upload and process CSV file with vehicle data
     * @return array<string, mixed>
     */
    public function uploadCsv(User $user, $request): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.create');

        // Get uploaded file
        $files = $request->uploadedFiles();
        if (empty($files['file'])) {
            throw new InvalidArgumentException('No file uploaded');
        }

        $file = $files['file'];

        // Read CSV content
        $csvContent = file_get_contents($file->getFilePath());
        if ($csvContent === false) {
            throw new InvalidArgumentException('Failed to read CSV file');
        }

        // Default mapping for standard CSV format
        $mapping = [
            'year' => 0,
            'make' => 1,
            'model' => 2,
            'engine' => 3,
            'transmission' => 4,
            'drive' => 5,
            'trim' => 6,
        ];

        // Import the CSV
        return $this->importer->import($csvContent, $mapping, false);
    }

    /**
     * @param array<string, int|string> $mapping
     * @return array<string, mixed>
     */
    public function importPreview(User $user, string $csv, array $mapping, int $limit = 20): array
    {
        $this->assertManageAccess($user);

        return $this->importer->preview($csv, $mapping, $limit);
    }

    /**
     * @param array<string, int|string> $mapping
     * @return array<string, mixed>
     */
    public function import(User $user, string $csv, array $mapping, bool $updateDuplicates = false): array
    {
        $this->assertManageAccess($user);

        return $this->importer->import($csv, $mapping, $updateDuplicates);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params): array
    {
        $filters = [];
        foreach (['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim', 'term'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $filters[$field] = $params[$field];
            }
        }

        return $filters;
    }

    /**
     * Decode a VIN and return vehicle information
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function decodeVin(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        if (!isset($data['vin'])) {
            throw new InvalidArgumentException('VIN is required');
        }

        if ($this->vinDecoder === null) {
            throw new \RuntimeException('VIN decoder service is not available');
        }

        $vin = (string) $data['vin'];

        // Validate format first
        if (!$this->vinDecoder->isValidFormat($vin)) {
            throw new InvalidArgumentException('Invalid VIN format. VIN must be 17 characters.');
        }

        // Decode the VIN
        $decoded = $this->vinDecoder->decode($vin);

        return [
            'vin' => $vin,
            'decoded' => $decoded,
            'message' => 'VIN decoded successfully',
        ];
    }

    /**
     * Run vehicle normalization job to populate missing data from VIN decoder
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function runNormalization(User $user, array $data = []): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.update');

        if ($this->normalizationJob === null) {
            throw new \RuntimeException('Vehicle normalization job is not available');
        }

        $batchSize = isset($data['batch_size']) ? max(1, min(500, (int) $data['batch_size'])) : 50;

        $result = $this->normalizationJob->run($batchSize);

        return [
            'message' => 'Normalization job completed',
            'processed' => $result['processed'],
            'normalized' => $result['normalized'],
            'skipped' => $result['skipped'],
        ];
    }

    /**
     * Validate VIN format
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateVin(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        if (!isset($data['vin'])) {
            throw new InvalidArgumentException('VIN is required');
        }

        if ($this->vinDecoder === null) {
            throw new \RuntimeException('VIN decoder service is not available');
        }

        $vin = (string) $data['vin'];
        $isValid = $this->vinDecoder->isValidFormat($vin);

        $response = [
            'vin' => $vin,
            'valid' => $isValid,
        ];

        if ($isValid) {
            $response['basic_info'] = $this->vinDecoder->getBasicInfo($vin);
        }

        return $response;
    }

    private function assertManageAccess(User $user): void
    {
        // Vehicle master data governs downstream dropdowns and normalization; restrict to manager/admin roles.
        $this->gate->assert($user, 'vehicles.*');
    }
}
