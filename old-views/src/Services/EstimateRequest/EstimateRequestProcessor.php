<?php

namespace App\Services\EstimateRequest;

use App\Database\Connection;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateRequest;
use App\Services\Customer\CustomerRepository;
use App\Services\Customer\CustomerValidator;
use App\Services\Customer\CustomerVehicleService;
use App\Services\Vehicle\VehicleMasterRepository;
use PDO;

/**
 * Processes estimate requests and creates draft estimates
 */
class EstimateRequestProcessor
{
    private Connection $connection;
    private EstimateRequestRepository $requestRepository;
    private CustomerRepository $customerRepository;
    private CustomerVehicleService $vehicleService;
    private VehicleMasterRepository $vehicleMasterRepository;

    public function __construct(
        Connection $connection,
        EstimateRequestRepository $requestRepository,
        CustomerRepository $customerRepository,
        CustomerVehicleService $vehicleService,
        VehicleMasterRepository $vehicleMasterRepository
    ) {
        $this->connection = $connection;
        $this->requestRepository = $requestRepository;
        $this->customerRepository = $customerRepository;
        $this->vehicleService = $vehicleService;
        $this->vehicleMasterRepository = $vehicleMasterRepository;
    }

    /**
     * Process an estimate request and create draft estimate
     *
     * @param EstimateRequest $request
     * @return array{estimate_id: int, customer_id: int, vehicle_id: int|null}
     */
    public function processRequest(EstimateRequest $request): array
    {
        $this->connection->pdo()->beginTransaction();

        try {
            // 1. Find or create customer
            $customer = $this->findOrCreateCustomer($request);

            // 2. Create vehicle if vehicle info provided
            $vehicleId = null;
            if ($request->vehicle_year !== null && $request->vehicle_make !== null && $request->vehicle_model !== null) {
                $vehicleId = $this->createVehicle($customer->id, $request);
            }

            // 3. Create draft estimate
            $estimateId = $this->createDraftEstimate($customer->id, $vehicleId, $request);

            // 4. Link estimate request to created records
            $this->requestRepository->linkToCustomerAndVehicle($request->id, $customer->id, $vehicleId);
            $this->requestRepository->linkToEstimate($request->id, $estimateId);

            $this->connection->pdo()->commit();

            return [
                'estimate_id' => $estimateId,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicleId,
            ];
        } catch (\Throwable $e) {
            $this->connection->pdo()->rollBack();
            throw $e;
        }
    }

    /**
     * Find existing customer by email or create new one
     */
    private function findOrCreateCustomer(EstimateRequest $request): Customer
    {
        // Try to find existing customer by email
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM customers WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $request->email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return new Customer($row);
        }

        // Create new customer
        return $this->customerRepository->create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'commercial' => false,
            'tax_exempt' => false,
            'notes' => 'Created from estimate request #' . $request->id,
        ]);
    }

    /**
     * Create vehicle for customer
     */
    private function createVehicle(int $customerId, EstimateRequest $request): int
    {
        // Try to find matching vehicle master record
        $vehicleMasterId = $this->findVehicleMasterId(
            $request->vehicle_year,
            $request->vehicle_make,
            $request->vehicle_model
        );

        // Get vehicle master details if found
        $master = null;
        if ($vehicleMasterId !== null) {
            $master = $this->vehicleMasterRepository->find($vehicleMasterId);
        }

        // If no master found, use defaults
        $engine = $master?->engine ?? 'Unknown';
        $transmission = $master?->transmission ?? 'Unknown';
        $drive = $master?->drive ?? 'Unknown';

        $vehicleData = [
            'vehicle_master_id' => $vehicleMasterId,
            'year' => $request->vehicle_year,
            'make' => $request->vehicle_make,
            'model' => $request->vehicle_model,
            'engine' => $engine,
            'transmission' => $transmission,
            'drive' => $drive,
            'vin' => $request->vin,
            'license_plate' => $request->license_plate,
            'notes' => 'Added from estimate request',
        ];

        $vehicle = $this->vehicleService->attachVehicle($customerId, $vehicleData);
        return (int) $vehicle['id'];
    }

    /**
     * Find vehicle master ID by year, make, model
     */
    private function findVehicleMasterId(?int $year, ?string $make, ?string $model): ?int
    {
        if ($year === null || $make === null || $model === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM vehicle_master
             WHERE year = :year AND make = :make AND model = :model
             LIMIT 1'
        );
        $stmt->execute([
            'year' => $year,
            'make' => $make,
            'model' => $model,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['id'] : null;
    }

    /**
     * Create draft estimate
     */
    private function createDraftEstimate(int $customerId, ?int $vehicleId, EstimateRequest $request): int
    {
        // Generate estimate number
        $estimateNumber = $this->generateEstimateNumber();

        // Determine service address
        $serviceAddress = $request->service_address_same_as_customer
            ? $request->address
            : $request->service_address;
        $serviceCity = $request->service_address_same_as_customer
            ? $request->city
            : $request->service_city;
        $serviceState = $request->service_address_same_as_customer
            ? $request->state
            : $request->service_state;
        $serviceZip = $request->service_address_same_as_customer
            ? $request->zip
            : $request->service_zip;

        // Create estimate record
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimates (
                number, customer_id, vehicle_id, status,
                subtotal, tax, call_out_fee, mileage_total, discounts, grand_total,
                customer_notes, internal_notes,
                created_at, updated_at
            ) VALUES (
                :number, :customer_id, :vehicle_id, 'draft',
                0, 0, 0, 0, 0, 0,
                :customer_notes, :internal_notes,
                NOW(), NOW()
            )
        SQL);

        $customerNotes = 'Service requested: ' . ($request->service_type_name ?? 'General service');
        if ($request->description) {
            $customerNotes .= "\n\nCustomer notes:\n" . $request->description;
        }

        $internalNotes = sprintf(
            "Created from estimate request #%d\n\nService Address:\n%s\n%s, %s %s",
            $request->id,
            $serviceAddress ?? 'N/A',
            $serviceCity ?? '',
            $serviceState ?? '',
            $serviceZip ?? ''
        );

        $stmt->execute([
            'number' => $estimateNumber,
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'customer_notes' => $customerNotes,
            'internal_notes' => $internalNotes,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * Generate next estimate number
     */
    private function generateEstimateNumber(): string
    {
        $stmt = $this->connection->pdo()->query(
            "SELECT number FROM estimates ORDER BY id DESC LIMIT 1"
        );
        $lastNumber = $stmt->fetchColumn();

        if (!$lastNumber) {
            return 'EST-' . date('Y') . '-0001';
        }

        // Extract numeric part and increment
        if (preg_match('/EST-\d{4}-(\d+)/', $lastNumber, $matches)) {
            $nextNum = (int) $matches[1] + 1;
            return 'EST-' . date('Y') . '-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        }

        // Fallback if pattern doesn't match
        return 'EST-' . date('Y') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}
