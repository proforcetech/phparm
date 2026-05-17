<?php

namespace App\Services\Subcontractor;

use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use App\Models\SubcontractorAssignmentPod;
use App\Models\SubcontractorPortalToken;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 18 / C2 — HTTP facade for the subcontractor self-service portal.
 *
 * Two surfaces:
 *   - Staff endpoints (issue / list / revoke tokens) — receive a User actor.
 *   - Sub-portal endpoints — receive a SubcontractorPortalToken (the route
 *     layer authenticates the bearer token before calling these).
 *
 * Token plaintext is returned exactly once (issueToken) so the staff user
 * who created it can copy it to the sub. The DB never stores plaintext.
 */
class SubcontractorPortalController
{
    public function __construct(private readonly SubcontractorPortalService $service)
    {
    }

    // ─────────────────────────────────────── staff token management ────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function issueToken(User $actor, int $subcontractorId, array $payload): array
    {
        $label = isset($payload['label']) && $payload['label'] !== ''
            ? (string) $payload['label']
            : null;
        $expires = isset($payload['expires_at']) && $payload['expires_at'] !== ''
            ? (string) $payload['expires_at']
            : null;
        $issued = $this->service->issueToken($actor, $subcontractorId, $label, $expires);
        return [
            'data' => [
                'token' => self::tokenToArray($issued['token']),
                'plaintext' => $issued['plaintext'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listTokens(User $actor, int $subcontractorId, bool $includeRevoked = false): array
    {
        $items = $this->service->listTokens($actor, $subcontractorId, $includeRevoked);
        return ['data' => array_map(self::tokenToArray(...), $items)];
    }

    public function revokeToken(User $actor, int $tokenId, ?string $reason = null): void
    {
        $this->service->revokeToken($actor, $tokenId, $reason);
    }

    // ─────────────────────────────────────────── self-service surface ──

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function login(array $payload, ?string $clientIp = null): array
    {
        $email = isset($payload['email']) ? (string) $payload['email'] : '';
        $password = isset($payload['password']) ? (string) $payload['password'] : '';
        $resolved = $this->service->authenticateCredentials($email, $password, $clientIp);
        if ($resolved === null) {
            throw new \App\Support\Auth\UnauthorizedException('Invalid credentials');
        }

        return [
            'data' => [
                'access_token' => $resolved['plaintext'],
                'token_type' => 'Bearer',
                'token' => self::tokenToArray($resolved['token']),
                'subcontractor' => self::subToPublicArray($resolved['subcontractor']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(SubcontractorPortalToken $token, Subcontractor $sub): array
    {
        return [
            'data' => [
                'subcontractor' => self::subToPublicArray($sub),
                'token' => self::tokenToArray($token),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMyAssignments(SubcontractorPortalToken $token, ?string $status = null): array
    {
        $items = $this->service->listMyAssignments($token, $status);
        return ['data' => array_map(self::assignmentToArray(...), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMyAssignment(SubcontractorPortalToken $token, int $assignmentId): array
    {
        $a = $this->service->findMyAssignment($token, $assignmentId);
        return ['data' => self::assignmentToArray($a)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function actOnAssignment(
        SubcontractorPortalToken $token,
        int $assignmentId,
        string $action,
        array $payload
    ): array {
        if (in_array($action, ['accept', 'decline', 'start', 'complete'], true)) {
            $a = $this->service->transition($token, $assignmentId, $action);
            // Detail update can ride along with complete (cost/notes typed
            // into the same modal). Keep the API ergonomic.
            if ($action === 'complete' && $payload !== []) {
                $a = $this->service->updateAssignmentDetails($token, $assignmentId, $payload);
            }
            return ['data' => self::assignmentToArray($a)];
        }
        throw new InvalidArgumentException("Unknown action: {$action}");
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateMyAssignment(
        SubcontractorPortalToken $token,
        int $assignmentId,
        array $payload
    ): array {
        $a = $this->service->updateAssignmentDetails($token, $assignmentId, $payload);
        return ['data' => self::assignmentToArray($a)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMyPods(SubcontractorPortalToken $token, int $assignmentId): array
    {
        $items = $this->service->listPods($token, $assignmentId);
        return ['data' => array_map(self::podToArray(...), $items)];
    }

    /**
     * @param array<string, mixed> $file $_FILES-style entry
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function uploadPod(
        SubcontractorPortalToken $token,
        int $assignmentId,
        array $file,
        array $body
    ): array {
        $kind = isset($body['kind']) && $body['kind'] !== ''
            ? (string) $body['kind']
            : SubcontractorAssignmentPod::KIND_POD;
        $note = isset($body['note']) ? (string) $body['note'] : null;
        $pod = $this->service->uploadPod($token, $assignmentId, $file, $kind, $note);
        return ['data' => self::podToArray($pod)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addNote(
        SubcontractorPortalToken $token,
        int $assignmentId,
        array $payload
    ): array {
        $note = isset($payload['note']) ? (string) $payload['note'] : '';
        $pod = $this->service->addNote($token, $assignmentId, $note);
        return ['data' => self::podToArray($pod)];
    }

    public function deleteOwnPod(SubcontractorPortalToken $token, int $podId): void
    {
        $this->service->deleteOwnPod($token, $podId);
    }

    /**
     * @return array<string, mixed>
     */
    public function listAssignmentPodsForStaff(User $actor, int $assignmentId): array
    {
        $items = $this->service->listPodsForStaff($actor, $assignmentId);
        return ['data' => array_map(self::podToArray(...), $items)];
    }

    // ─────────────────────────────────────────────── serializers ──────

    /**
     * Token serializer never includes the hash — the hash IS the secret-
     * equivalent and must not leave the server.
     *
     * @return array<string, mixed>
     */
    private static function tokenToArray(SubcontractorPortalToken $t): array
    {
        return [
            'id' => $t->id,
            'subcontractor_id' => $t->subcontractor_id,
            'label' => $t->label,
            'expires_at' => $t->expires_at,
            'last_used_at' => $t->last_used_at,
            'revoked_at' => $t->revoked_at,
            'revoked_reason' => $t->revoked_reason,
            'created_by_user_id' => $t->created_by_user_id,
            'created_at' => $t->created_at,
            'is_active' => $t->isActive(),
        ];
    }

    /**
     * Sub serializer for the portal: scrub fields the sub doesn't need to
     * see (internal notes, hourly rate, payment terms — those are *our*
     * commercial state, not theirs).
     *
     * @return array<string, mixed>
     */
    private static function subToPublicArray(Subcontractor $s): array
    {
        return [
            'id' => $s->id,
            'company_name' => $s->company_name,
            'contact_name' => $s->contact_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'status' => $s->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function assignmentToArray(SubcontractorAssignment $a): array
    {
        return $a->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private static function podToArray(SubcontractorAssignmentPod $p): array
    {
        $out = $p->toArray();
        // sha256 isn't a secret but we don't surface it to the portal; it's
        // useful internally for dedupe + integrity but noise on a sub's UI.
        unset($out['sha256']);
        return $out;
    }
}
