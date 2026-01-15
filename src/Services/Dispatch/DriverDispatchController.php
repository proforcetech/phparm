<?php

namespace App\Services\Dispatch;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;
use App\Database\Connection;

class DriverDispatchController
{
    private Connection $connection;
    private DriverPushTokenService $tokens;
    private DriverJobOfferService $offers;
    private AccessGate $gate;

    public function __construct(
        Connection $connection,
        DriverPushTokenService $tokens,
        DriverJobOfferService $offers,
        AccessGate $gate
    ) {
        $this->connection = $connection;
        $this->tokens = $tokens;
        $this->offers = $offers;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerPushToken(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'dispatch.tokens.manage')) {
            throw new UnauthorizedException('Cannot manage push tokens');
        }

        $driverProfileId = $this->resolveDriverProfileId($user->id);
        if ($driverProfileId === null) {
            throw new InvalidArgumentException('Driver profile not found');
        }

        $token = (string) ($payload['token'] ?? '');
        $platform = isset($payload['platform']) ? (string) $payload['platform'] : null;

        return $this->tokens->registerToken($driverProfileId, $token, $platform);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listOffers(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'dispatch.offers.view')) {
            throw new UnauthorizedException('Cannot view job offers');
        }

        $driverProfileId = isset($filters['driver_profile_id']) && $this->gate->can($user, 'dispatch.offers.create')
            ? (int) $filters['driver_profile_id']
            : $this->resolveDriverProfileId($user->id);

        if (!$driverProfileId) {
            throw new InvalidArgumentException('Driver profile not found');
        }

        return $this->offers->listOffers($driverProfileId, $filters);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOffer(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'dispatch.offers.create')) {
            throw new UnauthorizedException('Cannot create job offers');
        }

        if (isset($payload['driver_user_id']) && !isset($payload['driver_profile_id'])) {
            $driverProfileId = $this->resolveDriverProfileId((int) $payload['driver_user_id']);
            if ($driverProfileId === null) {
                throw new InvalidArgumentException('Driver profile not found');
            }
            $payload['driver_profile_id'] = $driverProfileId;
        }

        return $this->offers->createOffer($payload, $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptOffer(User $user, int $offerId): array
    {
        if (!$this->gate->can($user, 'dispatch.offers.accept')) {
            throw new UnauthorizedException('Cannot accept job offers');
        }

        $driverProfileId = $this->resolveDriverProfileId($user->id);
        if ($driverProfileId === null) {
            throw new InvalidArgumentException('Driver profile not found');
        }

        return $this->offers->acceptOffer($offerId, $driverProfileId);
    }

    /**
     * @return array<string, mixed>
     */
    public function declineOffer(
        User $user,
        int $offerId,
        ?string $rejectionReason = null,
        ?string $rejectionNotes = null
    ): array {
        if (!$this->gate->can($user, 'dispatch.offers.accept')) {
            throw new UnauthorizedException('Cannot decline job offers');
        }

        $driverProfileId = $this->resolveDriverProfileId($user->id);
        if ($driverProfileId === null) {
            throw new InvalidArgumentException('Driver profile not found');
        }

        return $this->offers->declineOffer($offerId, $driverProfileId, $rejectionReason, $rejectionNotes);
    }

    /**
     * Resolve the driver profile for a user (public for use in routes).
     *
     * @return array<string, mixed>|null
     */
    public function resolveDriverProfile(User $user): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT dp.*, u.name, u.email
             FROM driver_profiles dp
             INNER JOIN users u ON u.id = dp.user_id
             WHERE dp.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $user->id]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        return $profile !== false ? $profile : null;
    }

    private function resolveDriverProfileId(int $userId): ?int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM driver_profiles WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $value = $stmt->fetch(PDO::FETCH_COLUMN);
        return $value !== false ? (int) $value : null;
    }
}
