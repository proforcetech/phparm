<?php

namespace App\Services\Appointment;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class AppointmentController
{
    private AppointmentService $service;
    private AvailabilityService $availability;
    private AccessGate $gate;

    public function __construct(
        AppointmentService $service,
        AvailabilityService $availability,
        AccessGate $gate
    ) {
        $this->service = $service;
        $this->availability = $availability;
        $this->gate = $gate;
    }

    /**
     * List appointments
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'appointments.view')) {
            throw new UnauthorizedException('Cannot view appointments');
        }

        $appointments = $this->service->list($filters);

        return array_map(static fn ($appt) => $appt->toArray(), $appointments);
    }

    /**
     * Get single appointment
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'appointments.view')) {
            throw new UnauthorizedException('Cannot view appointments');
        }

        $appointment = $this->service->findById($id);

        if ($appointment === null) {
            throw new InvalidArgumentException('Appointment not found');
        }

        return $appointment->toArray();
    }

    /**
     * Create appointment
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'appointments.create')) {
            throw new UnauthorizedException('Cannot create appointments');
        }

        $appointment = $this->service->create($data, $user->id);

        return $appointment->toArray();
    }

    /**
     * Update appointment
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'appointments.update')) {
            throw new UnauthorizedException('Cannot update appointments');
        }

        $appointment = $this->service->update($id, $data, $user->id);

        if ($appointment === null) {
            throw new InvalidArgumentException('Appointment not found');
        }

        return $appointment->toArray();
    }

    /**
     * Delete appointment
     */
    public function destroy(User $user, int $id): void
    {
        if (!$this->gate->can($user, 'appointments.delete')) {
            throw new UnauthorizedException('Cannot delete appointments');
        }

        $deleted = $this->service->delete($id, $user->id);

        if (!$deleted) {
            throw new InvalidArgumentException('Appointment not found');
        }
    }

    /**
     * Get available time slots
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function availability(?User $user, array $params): array
    {
        $date = $params['date'] ?? date('Y-m-d');
        $technicianId = isset($params['technician_id']) ? (int) $params['technician_id'] : null;

        $slots = $this->availability->getAvailableSlots($date, $technicianId);

        return $slots;
    }

    /**
     * Update appointment status
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateStatus(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'appointments.update')) {
            throw new UnauthorizedException('Cannot update appointments');
        }

        if (!isset($data['status'])) {
            throw new InvalidArgumentException('status is required');
        }

        $appointment = $this->service->updateStatus($id, (string) $data['status'], $user->id);

        if ($appointment === null) {
            throw new InvalidArgumentException('Appointment not found');
        }

        return $appointment->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function availabilityConfig(User $user): array
    {
        if (!$this->gate->can($user, 'appointments.update')) {
            throw new UnauthorizedException('Cannot view availability settings');
        }

        return $this->availability->getConfig();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveAvailabilityConfig(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'appointments.update')) {
            throw new UnauthorizedException('Cannot update availability settings');
        }

        $this->availability->saveConfig($data);

        return $this->availability->getConfig();
    }
}
