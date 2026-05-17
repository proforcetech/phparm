<?php

namespace App\Services\Subcontractor;

use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use App\Models\SubcontractorAssignmentPod;
use App\Models\SubcontractorPortalToken;
use App\Models\User;
use App\Services\Portal\PortalUploadStorage;
use App\Services\Portal\PortalUploadValidator;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 18 / C2 — orchestrates the subcontractor self-service portal.
 *
 * Two distinct surfaces share this service:
 *   1) Staff-side issuance (issueToken / listTokens / revokeToken) — guarded
 *      by subcontractors.manage. Returns the plaintext token exactly once.
 *   2) Self-service (everything taking $token: SubcontractorPortalToken) —
 *      no User; the token is the portal access credential, scoped to one
 *      subcontractor row. Link tokens and email/password login both resolve
 *      to this same token boundary.
 *      Every method that reads or mutates an assignment validates the
 *      assignment.subcontractor_id == token.subcontractor_id. Cross-tenant
 *      access via guessed assignment IDs is impossible because of that check.
 *
 * The token-driven calls reuse PortalUploadValidator + PortalUploadStorage
 * (Phase 6.6) for POD uploads — same MIME allowlist, same partitioning,
 * same path-traversal protection, just under a sub-tenant directory.
 */
class SubcontractorPortalService
{
    private const TOKEN_PLAINTEXT_PREFIX = 'sub_';
    private const CREDENTIAL_SESSION_DAYS = 30;

    public function __construct(
        private readonly SubcontractorRepository $subRepo,
        private readonly SubcontractorPortalTokenRepository $tokenRepo,
        private readonly SubcontractorAssignmentPodRepository $podRepo,
        private readonly PortalUploadStorage $uploadStorage,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────── staff-side token management ────

    /**
     * Issue a new portal token for a subcontractor. Returns the plaintext
     * token exactly once — caller MUST relay it to the sub by a side
     * channel (email, SMS) and never again to the staff UI.
     *
     * @return array{token: SubcontractorPortalToken, plaintext: string}
     */
    public function issueToken(
        User $actor,
        int $subcontractorId,
        ?string $label = null,
        ?string $expiresAt = null
    ): array {
        $this->gate->assert($actor, 'subcontractors.manage');
        $sub = $this->subRepo->findSubcontractor($subcontractorId);
        if ($sub === null) {
            throw new InvalidArgumentException("Subcontractor {$subcontractorId} not found");
        }
        if ($sub->status !== Subcontractor::STATUS_ACTIVE) {
            throw new InvalidArgumentException(
                "Cannot issue token to non-active subcontractor (status: {$sub->status})"
            );
        }
        if ($expiresAt !== null && $expiresAt !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $expiresAt)) {
                throw new InvalidArgumentException('expires_at must be ISO date(time)');
            }
        }

        $plaintext = self::generatePlaintext();
        $token = $this->tokenRepo->create(
            $subcontractorId,
            $plaintext,
            $label,
            $expiresAt === '' ? null : $expiresAt,
            $actor->id ?? null,
        );
        return ['token' => $token, 'plaintext' => $plaintext];
    }

    /**
     * @return array<int, SubcontractorPortalToken>
     */
    public function listTokens(User $actor, int $subcontractorId, bool $includeRevoked = false): array
    {
        $this->gate->assert($actor, 'subcontractors.view');
        return $this->tokenRepo->listForSubcontractor($subcontractorId, $includeRevoked);
    }

    public function revokeToken(User $actor, int $tokenId, ?string $reason = null): void
    {
        $this->gate->assert($actor, 'subcontractors.manage');
        $token = $this->tokenRepo->findById($tokenId);
        if ($token === null) {
            throw new InvalidArgumentException("Token {$tokenId} not found");
        }
        $this->tokenRepo->revoke($tokenId, $reason);
    }

    // ─────────────────────────────────── token-authenticated self-service ───

    /**
     * Resolve a plaintext bearer token to its (sub, token) pair. The caller
     * (route layer) feeds this with whatever it pulled out of the request.
     * Returns null on any failure — invalid, expired, revoked, missing sub.
     *
     * @return array{token: SubcontractorPortalToken, subcontractor: Subcontractor}|null
     */
    public function authenticate(string $plaintext, ?string $clientIp = null): ?array
    {
        $clean = trim($plaintext);
        if (str_starts_with($clean, self::TOKEN_PLAINTEXT_PREFIX)) {
            $clean = substr($clean, strlen(self::TOKEN_PLAINTEXT_PREFIX));
        }
        if ($clean === '' || strlen($clean) < 32) {
            return null;
        }
        $token = $this->tokenRepo->findByPlaintext($clean);
        if ($token === null || !$token->isActive()) {
            return null;
        }
        $sub = $this->subRepo->findSubcontractor($token->subcontractor_id);
        if ($sub === null || $sub->status !== Subcontractor::STATUS_ACTIVE) {
            return null;
        }
        $this->tokenRepo->recordUse($token->id, $clientIp);
        return ['token' => $token, 'subcontractor' => $sub];
    }

    /**
     * Credential login uses the subcontractor's email + portal password and
     * returns a short-lived portal token for the existing self-service API.
     *
     * @return array{token: SubcontractorPortalToken, subcontractor: Subcontractor, plaintext: string}|null
     */
    public function authenticateCredentials(
        string $email,
        string $password,
        ?string $clientIp = null
    ): ?array {
        $cleanEmail = trim($email);
        if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if ($password === '') {
            return null;
        }

        $resolved = $this->subRepo->findPortalLoginByEmail($cleanEmail);
        if ($resolved === null) {
            return null;
        }

        $sub = $resolved['subcontractor'];
        if ($sub->status !== Subcontractor::STATUS_ACTIVE || !$sub->portal_login_enabled) {
            return null;
        }
        if (!password_verify($password, $resolved['password_hash'])) {
            return null;
        }

        $plaintext = self::generatePlaintext();
        $expiresAt = (new DateTimeImmutable('+' . self::CREDENTIAL_SESSION_DAYS . ' days'))
            ->format('Y-m-d H:i:s');
        $token = $this->tokenRepo->create(
            $sub->id,
            $plaintext,
            'Credential login',
            $expiresAt,
            null,
        );
        $this->subRepo->recordPortalLogin($sub->id, $clientIp);
        $this->tokenRepo->recordUse($token->id, $clientIp);

        return [
            'token' => $token,
            'subcontractor' => $sub,
            'plaintext' => $plaintext,
        ];
    }

    /**
     * Self-service: list this sub's open + recent assignments. Defaults to
     * everything still actionable (pending / accepted / in_progress) plus
     * the most recent 25 historical rows for context.
     *
     * @return array<int, SubcontractorAssignment>
     */
    public function listMyAssignments(SubcontractorPortalToken $token, ?string $statusFilter = null): array
    {
        $filters = ['subcontractor_id' => $token->subcontractor_id, 'limit' => 100];
        if ($statusFilter !== null && $statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }
        return $this->subRepo->listAssignments($filters);
    }

    public function findMyAssignment(SubcontractorPortalToken $token, int $assignmentId): SubcontractorAssignment
    {
        $a = $this->subRepo->findAssignment($assignmentId);
        if ($a === null || $a->subcontractor_id !== $token->subcontractor_id) {
            throw new InvalidArgumentException('Assignment not available to this token');
        }
        return $a;
    }

    /**
     * Self-service status transitions. Reuses the same allowed-transitions
     * table SubcontractorService enforces for staff (so the state machine
     * is single-sourced) but additionally restricts which targets the sub
     * may pick:
     *   - accept     (pending → accepted)
     *   - decline    (pending → declined)
     *   - start      (pending|accepted → in_progress)
     *   - complete   (accepted|in_progress → completed)
     * Anything else (cancel, etc.) stays staff-only.
     */
    public function transition(
        SubcontractorPortalToken $token,
        int $assignmentId,
        string $action,
        ?DateTimeImmutable $now = null
    ): SubcontractorAssignment {
        $assignment = $this->findMyAssignment($token, $assignmentId);
        $now ??= new DateTimeImmutable();
        $stamp = $now->format('Y-m-d H:i:s');

        $update = [];
        switch ($action) {
            case 'accept':
                $this->assertCurrentIs($assignment, [SubcontractorAssignment::STATUS_PENDING]);
                $update['status'] = SubcontractorAssignment::STATUS_ACCEPTED;
                $update['accepted_at'] = $stamp;
                break;
            case 'decline':
                $this->assertCurrentIs($assignment, [SubcontractorAssignment::STATUS_PENDING]);
                $update['status'] = SubcontractorAssignment::STATUS_DECLINED;
                $update['declined_at'] = $stamp;
                break;
            case 'start':
                $this->assertCurrentIs($assignment, [
                    SubcontractorAssignment::STATUS_PENDING,
                    SubcontractorAssignment::STATUS_ACCEPTED,
                ]);
                $update['status'] = SubcontractorAssignment::STATUS_IN_PROGRESS;
                if ($assignment->status === SubcontractorAssignment::STATUS_PENDING) {
                    // implicit accept-on-start so the timeline is coherent
                    $update['accepted_at'] = $assignment->accepted_at ?? $stamp;
                }
                break;
            case 'complete':
                $this->assertCurrentIs($assignment, [
                    SubcontractorAssignment::STATUS_ACCEPTED,
                    SubcontractorAssignment::STATUS_IN_PROGRESS,
                ]);
                $update['status'] = SubcontractorAssignment::STATUS_COMPLETED;
                $update['completed_at'] = $stamp;
                break;
            default:
                throw new InvalidArgumentException(
                    "Unknown self-service action: {$action}"
                );
        }
        return $this->subRepo->updateAssignment($assignment->id, $update);
    }

    /**
     * Self-service free-form payload update — only the fields a sub may
     * legitimately revise themselves. Cost / notes / decline-reason; never
     * status. Status changes go through transition().
     *
     * @param array<string, mixed> $data
     */
    public function updateAssignmentDetails(
        SubcontractorPortalToken $token,
        int $assignmentId,
        array $data
    ): SubcontractorAssignment {
        $assignment = $this->findMyAssignment($token, $assignmentId);
        $allowed = [];
        if (array_key_exists('actual_cost_cents', $data)) {
            $v = $data['actual_cost_cents'];
            if ($v !== null && $v !== '') {
                $i = (int) $v;
                if ($i < 0) {
                    throw new InvalidArgumentException('actual_cost_cents must be non-negative');
                }
                $allowed['actual_cost_cents'] = $i;
            } else {
                $allowed['actual_cost_cents'] = null;
            }
        }
        if (array_key_exists('description', $data)) {
            $allowed['description'] = $data['description'] === null ? null : (string) $data['description'];
        }
        if ($allowed === []) {
            return $assignment;
        }
        return $this->subRepo->updateAssignment($assignment->id, $allowed);
    }

    // ─────────────────────────────────────────────── POD uploads / notes ────

    /**
     * @return array<int, SubcontractorAssignmentPod>
     */
    public function listPods(SubcontractorPortalToken $token, int $assignmentId): array
    {
        $this->findMyAssignment($token, $assignmentId);
        return $this->podRepo->listForAssignment($assignmentId);
    }

    /**
     * Attach a text-only note to the assignment's POD bundle. No file
     * upload involved.
     */
    public function addNote(
        SubcontractorPortalToken $token,
        int $assignmentId,
        string $note
    ): SubcontractorAssignmentPod {
        $assignment = $this->findMyAssignment($token, $assignmentId);
        $clean = trim($note);
        if ($clean === '') {
            throw new InvalidArgumentException('note text is required');
        }
        return $this->podRepo->create([
            'assignment_id' => $assignment->id,
            'subcontractor_id' => $token->subcontractor_id,
            'kind' => SubcontractorAssignmentPod::KIND_NOTE,
            'notes' => substr($clean, 0, 4000),
            'uploaded_via_token_id' => $token->id,
        ]);
    }

    /**
     * Validate + persist an uploaded POD file. Reuses the Phase 6.6 portal
     * upload validator (sha256 + finfo MIME + size cap) so a sub can't
     * upload anything our staff portal couldn't either.
     *
     * @param array<string, mixed> $file $_FILES-style entry
     */
    public function uploadPod(
        SubcontractorPortalToken $token,
        int $assignmentId,
        array $file,
        string $kind = SubcontractorAssignmentPod::KIND_POD,
        ?string $note = null,
        bool $requireUploadedFile = true
    ): SubcontractorAssignmentPod {
        $assignment = $this->findMyAssignment($token, $assignmentId);
        if (!in_array($kind, SubcontractorAssignmentPod::KINDS, true)
            || $kind === SubcontractorAssignmentPod::KIND_NOTE) {
            throw new InvalidArgumentException("Invalid file kind: {$kind}");
        }

        $validated = PortalUploadValidator::validate($file, $requireUploadedFile);

        // Partition uploads under the sub's id so even if the public/uploads
        // root were directory-listed (misconfig), the listing wouldn't mix
        // sub uploads with customer-portal uploads.
        $alloc = $this->uploadStorage->allocatePath(
            $token->subcontractor_id,
            $validated['extension']
        );
        try {
            $this->uploadStorage->persist($validated['tmp_name'], $alloc['abs_path']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not persist POD upload: ' . $e->getMessage(), 0, $e);
        }

        return $this->podRepo->create([
            'assignment_id' => $assignment->id,
            'subcontractor_id' => $token->subcontractor_id,
            'kind' => $kind,
            'original_name' => $validated['original_name'],
            'stored_path' => $alloc['rel_path'],
            'mime_type' => $validated['mime_type'],
            'size_bytes' => $validated['size'],
            'sha256' => $validated['sha256'],
            'notes' => $note === null || trim($note) === '' ? null : substr(trim($note), 0, 4000),
            'uploaded_via_token_id' => $token->id,
        ]);
    }

    /**
     * Soft-delete a POD the sub uploaded themselves. The file on disk is
     * left in place; a separate cleanup job purges by deleted_at age.
     */
    public function deleteOwnPod(SubcontractorPortalToken $token, int $podId): void
    {
        $pod = $this->podRepo->find($podId);
        if ($pod === null
            || $pod->subcontractor_id !== $token->subcontractor_id
            || $pod->deleted_at !== null) {
            throw new InvalidArgumentException('POD not available to this token');
        }
        $this->podRepo->softDelete($podId);
    }

    // ───────────────────────────────────── staff-side POD listing helper ────

    /**
     * Staff read of all PODs for an assignment. Used by the existing
     * subcontractor staff UI to surface what the sub uploaded.
     *
     * @return array<int, SubcontractorAssignmentPod>
     */
    public function listPodsForStaff(User $actor, int $assignmentId): array
    {
        $this->gate->assert($actor, 'subcontractors.view');
        $assignment = $this->subRepo->findAssignment($assignmentId);
        if ($assignment === null) {
            throw new InvalidArgumentException("Assignment {$assignmentId} not found");
        }
        return $this->podRepo->listForAssignment($assignmentId);
    }

    // ──────────────────────────────────────────────────── helpers ───────────

    /**
     * @param array<int, string> $allowed
     */
    private function assertCurrentIs(SubcontractorAssignment $a, array $allowed): void
    {
        if (!in_array($a->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "Action not allowed from current status: {$a->status}"
            );
        }
    }

    /**
     * 32-byte URL-safe random secret. Caller prefixes "sub_" only at the
     * presentation layer so the stored hash is independent of the prefix.
     */
    private static function generatePlaintext(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            throw new RuntimeException('random_bytes unavailable: ' . $e->getMessage(), 0, $e);
        }
    }
}
