<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\PortalUpload;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Customer\CustomerRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Phase 6.6 of docs/expansion-plan.md — portal document/photo upload.
 *
 * Three upload surfaces (tickets, workorders, message threads), one
 * polymorphic table, one scope-enforcement class. Mirrors the Phase
 * 6.3–6.5 pattern: assertUsable(account) → company/site match → entity
 * load → repo write → audit.
 *
 * Files live on disk at public/uploads/portal/{company_id}/{yyyymm}/
 * (via PortalUploadStorage); only the relative path is stored in the
 * DB. Deletion is soft — the row is marked deleted_at but the file
 * stays on disk until a separate cleanup job unlinks it, so audit
 * trails can point at "what used to be here" without the storage going
 * out from under them.
 *
 * Download path re-runs scope on every hit — a URL that worked last
 * month for a now-revoked account will 401 at download time, not just
 * at list time.
 */
class PortalUploadService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PortalUploadRepository $uploads,
        private readonly PortalUploadStorage $storage,
        private readonly TicketRepository $tickets,
        private readonly WorkorderRepository $workorders,
        private readonly CustomerRepository $customers,
        private readonly PortalAuthService $portalAuth,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function uploadForTicket(
        User $user,
        PortalAccount $account,
        int $ticketId,
        array $file,
        bool $requireUploadedFile = true,
    ): array {
        $ticket = $this->loadScopedTicket($account, $ticketId);
        $validated = PortalUploadValidator::validate($file, $requireUploadedFile);
        return $this->persistAndRecord(
            $user,
            $account,
            PortalUploadRepository::ENTITY_TICKET,
            $ticket->id,
            $validated,
            ['ticket_id' => $ticket->id, 'company_id' => (int) ($ticket->company_id ?? 0)],
        );
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function uploadForWorkorder(
        User $user,
        PortalAccount $account,
        int $workorderId,
        array $file,
        bool $requireUploadedFile = true,
    ): array {
        $wo = $this->loadScopedWorkorder($account, $workorderId);
        $validated = PortalUploadValidator::validate($file, $requireUploadedFile);
        return $this->persistAndRecord(
            $user,
            $account,
            PortalUploadRepository::ENTITY_WORKORDER,
            $wo->id,
            $validated,
            ['workorder_id' => $wo->id, 'customer_id' => $wo->customer_id],
        );
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function uploadForThread(
        User $user,
        PortalAccount $account,
        int $threadId,
        array $file,
        bool $requireUploadedFile = true,
    ): array {
        $thread = $this->loadScopedThread($user, $account, $threadId);
        $validated = PortalUploadValidator::validate($file, $requireUploadedFile);
        return $this->persistAndRecord(
            $user,
            $account,
            PortalUploadRepository::ENTITY_MESSAGE_THREAD,
            $thread['id'],
            $validated,
            [
                'thread_id' => $thread['id'],
                'ticket_id' => $thread['ticket_id'],
                'workorder_id' => $thread['workorder_id'],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTicket(User $user, PortalAccount $account, int $ticketId): array
    {
        $ticket = $this->loadScopedTicket($account, $ticketId);
        return array_map(
            fn(PortalUpload $u) => $this->serialize($u),
            $this->uploads->listForEntity(PortalUploadRepository::ENTITY_TICKET, $ticket->id),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForWorkorder(User $user, PortalAccount $account, int $workorderId): array
    {
        $wo = $this->loadScopedWorkorder($account, $workorderId);
        return array_map(
            fn(PortalUpload $u) => $this->serialize($u),
            $this->uploads->listForEntity(PortalUploadRepository::ENTITY_WORKORDER, $wo->id),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForThread(User $user, PortalAccount $account, int $threadId): array
    {
        $thread = $this->loadScopedThread($user, $account, $threadId);
        return array_map(
            fn(PortalUpload $u) => $this->serialize($u),
            $this->uploads->listForEntity(PortalUploadRepository::ENTITY_MESSAGE_THREAD, $thread['id']),
        );
    }

    /**
     * Load a single upload for download. Re-runs full scope through
     * the owning entity so a revoked account / cross-company hit /
     * site-whitelist mismatch all reject even if they hold a URL.
     */
    public function getUploadForDownload(
        User $user,
        PortalAccount $account,
        int $uploadId,
    ): PortalUpload {
        $this->assertUsable($account);
        $upload = $this->requireUpload($uploadId);
        $this->assertUploadInScope($user, $account, $upload);
        return $upload;
    }

    public function deleteUpload(User $user, PortalAccount $account, int $uploadId): void
    {
        $this->assertUsable($account);
        $upload = $this->requireUpload($uploadId);
        $this->assertUploadInScope($user, $account, $upload);

        if ($upload->portal_account_id !== $account->id) {
            throw new UnauthorizedException('upload was not created by this portal_account');
        }

        if (!$this->uploads->softDelete($upload->id)) {
            throw new RuntimeException('could not delete upload ' . $upload->id);
        }
        // Physical unlink is best-effort and fire-and-forget: if it
        // fails (nfs hiccup, file already gone), audit still goes.
        $this->storage->unlink($upload->stored_path);

        $this->audit->log(new AuditEntry(
            'portal.upload.deleted',
            'portal_upload',
            $upload->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'entity_type' => $upload->entity_type,
                'entity_id' => $upload->entity_id,
                'size_bytes' => $upload->size_bytes,
            ],
        ));
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @param array{mime_type: string, extension: string, size: int, original_name: string, tmp_name: string, sha256: string} $validated
     * @param array<string, mixed> $auditContext
     * @return array<string, mixed>
     */
    private function persistAndRecord(
        User $user,
        PortalAccount $account,
        string $entityType,
        int $entityId,
        array $validated,
        array $auditContext,
    ): array {
        $paths = $this->storage->allocatePath($account->company_id, $validated['extension']);
        $this->storage->persist($validated['tmp_name'], $paths['abs_path']);

        try {
            $id = $this->uploads->create([
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'original_name' => $validated['original_name'],
                'stored_path' => $paths['rel_path'],
                'mime_type' => $validated['mime_type'],
                'size_bytes' => $validated['size'],
                'sha256' => $validated['sha256'],
                'uploaded_by_user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            // Roll back the filesystem write if the DB insert fails so
            // we don't leave orphan files on disk.
            $this->storage->unlink($paths['rel_path']);
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'portal.upload.created',
            'portal_upload',
            $id,
            $user->id,
            array_merge([
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'mime_type' => $validated['mime_type'],
                'size_bytes' => $validated['size'],
                'sha256' => $validated['sha256'],
            ], $auditContext),
        ));

        $upload = $this->uploads->findById($id);
        if ($upload === null) {
            throw new RuntimeException("upload {$id} vanished after insert");
        }
        return $this->serialize($upload);
    }

    private function requireUpload(int $uploadId): PortalUpload
    {
        if ($uploadId <= 0) {
            throw new InvalidArgumentException('upload id is required');
        }
        $upload = $this->uploads->findById($uploadId);
        if ($upload === null) {
            throw new InvalidArgumentException("upload {$uploadId} not found");
        }
        return $upload;
    }

    /**
     * Re-runs scope for an upload based on its owning entity. The
     * portal_account_id match is a necessary but not sufficient check
     * for downloads — an account might lose access to a site/ticket
     * after uploading (allowed_site_ids narrowed, ticket reassigned to
     * another company, etc.), and the download endpoint must still
     * reject. So we walk the entity every time.
     */
    private function assertUploadInScope(User $user, PortalAccount $account, PortalUpload $upload): void
    {
        if ($upload->company_id !== $account->company_id) {
            throw new UnauthorizedException('upload belongs to a different company');
        }
        switch ($upload->entity_type) {
            case PortalUploadRepository::ENTITY_TICKET:
                $this->loadScopedTicket($account, $upload->entity_id);
                return;
            case PortalUploadRepository::ENTITY_WORKORDER:
                $this->loadScopedWorkorder($account, $upload->entity_id);
                return;
            case PortalUploadRepository::ENTITY_MESSAGE_THREAD:
                $this->loadScopedThread($user, $account, $upload->entity_id);
                return;
        }
        throw new RuntimeException(
            "unknown entity_type {$upload->entity_type} on upload {$upload->id}"
        );
    }

    private function loadScopedTicket(PortalAccount $account, int $ticketId): Ticket
    {
        $this->assertUsable($account);
        if ($ticketId <= 0) {
            throw new InvalidArgumentException('ticket id is required');
        }
        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException("ticket {$ticketId} not found");
        }
        if ((int) ($ticket->company_id ?? 0) !== $account->company_id) {
            throw new UnauthorizedException('ticket belongs to a different company');
        }
        if ($ticket->site_id !== null) {
            $this->portalAuth->assertSiteAccess($account, (int) $ticket->site_id);
        }
        return $ticket;
    }

    private function loadScopedWorkorder(PortalAccount $account, int $workorderId): Workorder
    {
        $this->assertUsable($account);
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder id is required');
        }
        $wo = $this->workorders->find($workorderId);
        if ($wo === null) {
            throw new InvalidArgumentException("workorder {$workorderId} not found");
        }
        $customer = $this->customers->find($wo->customer_id);
        if ($customer === null
            || (int) ($customer->company_id ?? 0) !== $account->company_id
        ) {
            throw new UnauthorizedException('workorder belongs to a different company');
        }
        return $wo;
    }

    /**
     * @return array{id: int, ticket_id: ?int, workorder_id: ?int}
     */
    private function loadScopedThread(User $user, PortalAccount $account, int $threadId): array
    {
        $this->assertUsable($account);
        if ($threadId <= 0) {
            throw new InvalidArgumentException('thread id is required');
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, ticket_id, workorder_id FROM message_threads WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException("thread {$threadId} not found");
        }

        $ticketId = $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
        $workorderId = $row['workorder_id'] !== null ? (int) $row['workorder_id'] : null;
        $linked = false;
        if ($ticketId !== null) {
            $this->loadScopedTicket($account, $ticketId);
            $linked = true;
        }
        if ($workorderId !== null) {
            $this->loadScopedWorkorder($account, $workorderId);
            $linked = true;
        }
        if (!$linked) {
            throw new UnauthorizedException(
                'thread is not linked to a portal-accessible ticket or workorder'
            );
        }

        $check = $this->connection->pdo()->prepare(
            'SELECT 1 FROM message_participants
             WHERE thread_id = :tid AND participant_id = :uid LIMIT 1'
        );
        $check->execute(['tid' => $threadId, 'uid' => $user->id]);
        if (!$check->fetchColumn()) {
            throw new UnauthorizedException('user is not a participant in this thread');
        }

        return [
            'id' => (int) $row['id'],
            'ticket_id' => $ticketId,
            'workorder_id' => $workorderId,
        ];
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PortalUpload $u): array
    {
        return [
            'id' => $u->id,
            'entity_type' => $u->entity_type,
            'entity_id' => $u->entity_id,
            'original_name' => $u->original_name,
            'mime_type' => $u->mime_type,
            'size_bytes' => $u->size_bytes,
            'sha256' => $u->sha256,
            'uploaded_by_user_id' => $u->uploaded_by_user_id,
            'portal_account_id' => $u->portal_account_id,
            'created_at' => $u->created_at,
        ];
    }
}
