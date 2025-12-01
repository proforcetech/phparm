<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

class VehicleNormalizationJob
{
    private Connection $connection;
    private VehicleMasterRepository $vehicles;
    private VinDecoderInterface $decoder;

    public function __construct(Connection $connection, VehicleMasterRepository $vehicles, VinDecoderInterface $decoder)
    {
        $this->connection = $connection;
        $this->vehicles = $vehicles;
        $this->decoder = $decoder;
    }

    /**
     * Hydrate customer vehicles missing normalized data by leveraging VIN decoding.
     *
     * @return array{processed: int, normalized: int, skipped: int}
     */
    public function run(int $batchSize = 50): array
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, vin, year, make, model, engine, transmission, drive, trim, vehicle_master_id '
            . 'FROM customer_vehicles '
            . 'WHERE vin IS NOT NULL AND vin != "" '
            . 'AND (vehicle_master_id IS NULL OR trim IS NULL OR engine = "" OR transmission = "" OR drive = "") '
            . 'LIMIT :limit'
        );
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $normalized = 0;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $processed++;
            $decoded = $this->decoder->decode($row['vin']);
            if ($decoded === [] || empty($decoded['year']) || empty($decoded['make']) || empty($decoded['model'])) {
                continue;
            }

            $payload = [
                'year' => (int) ($decoded['year'] ?? $row['year']),
                'make' => (string) ($decoded['make'] ?? $row['make']),
                'model' => (string) ($decoded['model'] ?? $row['model']),
                'engine' => (string) ($decoded['engine'] ?? $row['engine']),
                'transmission' => (string) ($decoded['transmission'] ?? $row['transmission']),
                'drive' => (string) ($decoded['drive'] ?? $row['drive']),
                'trim' => $decoded['trim'] ?? ($row['trim'] ?: null),
            ];

            $master = $this->vehicles->findByAttributes($payload) ?? $this->vehicles->create($payload);

            $update = $pdo->prepare(
                'UPDATE customer_vehicles SET vehicle_master_id = :master, year = :year, make = :make, model = :model, '
                . 'engine = :engine, transmission = :transmission, drive = :drive, trim = :trim, updated_at = :updated_at '
                . 'WHERE id = :id'
            );

            $update->execute([
                'master' => $master->id,
                'year' => $master->year,
                'make' => $master->make,
                'model' => $master->model,
                'engine' => $master->engine,
                'transmission' => $master->transmission,
                'drive' => $master->drive,
                'trim' => $master->trim,
                'updated_at' => $now,
                'id' => $row['id'],
            ]);

            $normalized++;
        }

        return [
            'processed' => $processed,
            'normalized' => $normalized,
            'skipped' => $processed - $normalized,
        ];
    }
}
