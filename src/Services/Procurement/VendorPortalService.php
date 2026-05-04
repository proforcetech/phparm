<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDocument;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPortalToken;
use App\Services\Portal\PortalUploadStorage;
use App\Services\Portal\PortalUploadValidator;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 18 / C1 — orchestrates the vendor self-service portal.
 *
 * Two distinct surfaces share this service:
 *   1) Staff-side issuance (issueToken / listTokens / revokeToken) — guarded
 *      by procurement.manage. Returns the plaintext token exactly once.
 *   2) Self-service (everything taking $token: VendorPortalToken) — no User;
 *      the token IS the auth, scoped to one vendor row. Every method that
 *      reads or mutates a PO validates po.vendor_id == token.vendor_id.
 *      Cross-tenant access via guessed PO IDs is impossible because of that
 *      check.
 *
 * The token-driven calls reuse PortalUploadValidator + PortalUploadStorage
 * (Phase 6.6) for document uploads — same MIME allowlist, same partitioning,
 * same path-traversal protection, just under a vendor-tenant directory.
 */
class VendorPortalService
{
    private const TOKEN_PLAINTEXT_PREFIX = 'ven_';

    public function __construct(
        private readonly VendorRepository $vendorRepo,
        private readonly PurchaseOrderRepository $poRepo,
        private readonly VendorPortalTokenRepository $tokenRepo,
        private readonly PurchaseOrderDocumentRepository $docRepo,
        private readonly PortalUploadStorage $uploadStorage,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────── staff-side token management ────

    /**
     * Issue a new portal token for a vendor. Returns the plaintext token
     * exactly once — caller MUST relay it to the vendor by a side channel
     * (email) and never again to the staff UI.
     *
     * @return array{token: VendorPortalToken, plaintext: string}
     */
    public function issueToken(
        User $actor,
        int $vendorId,
        ?string $label = null,
        ?string $expiresAt = null
    ): array {
        $this->gate->assert($actor, 'procurement.manage');
        $vendor = $this->vendorRepo->findById($vendorId);
        if ($vendor === null) {
            throw new InvalidArgumentException("Vendor {$vendorId} not found");
        }
        if ($vendor->status !== Vendor::STATUS_ACTIVE) {
            throw new InvalidArgumentException(
                "Cannot issue token to non-active vendor (status: {$vendor->status})"
            );
        }
        if ($expiresAt !== null && $expiresAt !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $expiresAt)) {
                throw new InvalidArgumentException('expires_at must be ISO date(time)');
            }
        }

        $plaintext = self::generatePlaintext();
        $token = $this->tokenRepo->create(
            $vendorId,
            $plaintext,
            $label,
            $expiresAt === '' ? null : $expiresAt,
            $actor->id ?? null,
        );
        return [
            'token' => $token,
            'plaintext' => self::TOKEN_PLAINTEXT_PREFIX . $plaintext,
        ];
    }

    /**
     * @return array<int, VendorPortalToken>
     */
    public function listTokens(User $actor, int $vendorId, bool $includeRevoked = false): array
    {
        $this->gate->assert($actor, 'procurement.view');
        return $this->tokenRepo->listForVendor($vendorId, $includeRevoked);
    }

    public function revokeToken(User $actor, int $tokenId, ?string $reason = null): void
    {
        $this->gate->assert($actor, 'procurement.manage');
        $token = $this->tokenRepo->findById($tokenId);
        if ($token === null) {
            throw new InvalidArgumentException("Token {$tokenId} not found");
        }
        $this->tokenRepo->revoke($tokenId, $reason);
    }

    // ─────────────────────────────────── token-authenticated self-service ───

    /**
     * Resolve a plaintext bearer token to its (vendor, token) pair. Returns
     * null on any failure — invalid, expired, revoked, missing vendor, or
     * vendor not active.
     *
     * @return array{token: VendorPortalToken, vendor: Vendor}|null
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
        $vendor = $this->vendorRepo->findById($token->vendor_id);
        if ($vendor === null || $vendor->status !== Vendor::STATUS_ACTIVE) {
            return null;
        }
        $this->tokenRepo->recordUse($token->id, $clientIp);
        return ['token' => $token, 'vendor' => $vendor];
    }

    /**
     * List the vendor's POs the portal should surface — only ones we've
     * actually sent (drafts and cancelled hidden by default). The staff
     * side still has the unfiltered view through PurchaseOrderService.
     *
     * @return array<int, PurchaseOrder>
     */
    public function listMyPos(VendorPortalToken $token, ?string $statusFilter = null): array
    {
        $filters = ['vendor_id' => $token->vendor_id, 'limit' => 100];
        if ($statusFilter !== null && $statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }
        $result = $this->poRepo->search($filters);
        // Hide drafts: vendor shouldn't see a PO until staff actually sends it.
        return array_values(array_filter(
            $result['data'],
            static fn(PurchaseOrder $po) => $po->status !== PurchaseOrder::STATUS_DRAFT,
        ));
    }

    /**
     * Detail bundle for a single PO: header + lines + uploaded documents.
     * Throws if the PO doesn't belong to this vendor or is still draft.
     *
     * @return array{po: PurchaseOrder, lines: array<int, PurchaseOrderLine>, documents: array<int, PurchaseOrderDocument>}
     */
    public function getMyPo(VendorPortalToken $token, int $poId): array
    {
        $po = $this->findMyPo($token, $poId);
        return [
            'po' => $po,
            'lines' => $this->poRepo->listLines($poId),
            'documents' => $this->docRepo->listForPurchaseOrder($poId),
        ];
    }

    public function findMyPo(VendorPortalToken $token, int $poId): PurchaseOrder
    {
        $po = $this->poRepo->findHeader($poId);
        if ($po === null
            || $po->vendor_id !== $token->vendor_id
            || $po->status === PurchaseOrder::STATUS_DRAFT) {
            throw new InvalidArgumentException('Purchase order not available to this token');
        }
        return $po;
    }

    /**
     * Vendor acknowledges receipt of a sent PO — sets a timestamp; does NOT
     * change status (staff still drives the formal status machine).
     */
    public function acknowledgePo(
        VendorPortalToken $token,
        int $poId,
        ?DateTimeImmutable $now = null
    ): PurchaseOrder {
        $po = $this->findMyPo($token, $poId);
        if ($po->vendor_acknowledged_at !== null) {
            // Idempotent: no-op if already acknowledged.
            return $po;
        }
        if (!in_array($po->status, [
            PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_PARTIAL,
        ], true)) {
            throw new InvalidArgumentException(
                "Cannot acknowledge PO in status: {$po->status}"
            );
        }
        $now ??= new DateTimeImmutable();
        return $this->poRepo->updateHeader($poId, [
            'vendor_acknowledged_at' => $now->format('Y-m-d H:i:s'),
            'vendor_acknowledged_via_token_id' => $token->id,
        ]);
    }

    /**
     * Vendor marks a line as shipped from their warehouse. Does NOT
     * increment quantity_received — that still happens when our parts
     * staff physically receive the shipment. This just records the
     * shipping event so dispatch/parts know to expect it.
     *
     * @param array{tracking_number?: ?string, carrier?: ?string, shipped_at?: ?string} $data
     */
    public function markLineShipped(
        VendorPortalToken $token,
        int $lineId,
        array $data,
        ?DateTimeImmutable $now = null
    ): PurchaseOrderLine {
        $line = $this->poRepo->findLine($lineId);
        if ($line === null) {
            throw new InvalidArgumentException("Line {$lineId} not found");
        }
        $po = $this->findMyPo($token, $line->purchase_order_id);
        if (!in_array($po->status, [
            PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_PARTIAL,
        ], true)) {
            throw new InvalidArgumentException(
                "Cannot mark shipped on PO in status: {$po->status}"
            );
        }
        if ($line->status === PurchaseOrderLine::STATUS_CANCELLED
            || $line->status === PurchaseOrderLine::STATUS_RECEIVED) {
            throw new InvalidArgumentException(
                "Cannot mark line shipped from status: {$line->status}"
            );
        }
        $now ??= new DateTimeImmutable();
        $shippedAt = isset($data['shipped_at']) && $data['shipped_at'] !== ''
            ? (string) $data['shipped_at']
            : $now->format('Y-m-d H:i:s');
        $tracking = isset($data['tracking_number']) && $data['tracking_number'] !== ''
            ? substr((string) $data['tracking_number'], 0, 120)
            : null;
        $carrier = isset($data['carrier']) && $data['carrier'] !== ''
            ? substr((string) $data['carrier'], 0, 80)
            : null;

        return $this->poRepo->updateLine($lineId, [
            'vendor_shipped_at' => $shippedAt,
            'vendor_tracking_number' => $tracking,
            'vendor_carrier' => $carrier,
        ]);
    }

    // ─────────────────────────────────────────────── document uploads ────

    /**
     * @return array<int, PurchaseOrderDocument>
     */
    public function listDocuments(VendorPortalToken $token, int $poId): array
    {
        $this->findMyPo($token, $poId);
        return $this->docRepo->listForPurchaseOrder($poId);
    }

    /**
     * Validate + persist an uploaded document against a PO. Reuses the
     * Phase 6.6 portal upload validator (sha256 + finfo MIME + size cap)
     * so a vendor can't upload anything our staff portal couldn't either.
     *
     * @param array<string, mixed> $file $_FILES-style entry
     * @param array<string, mixed> $meta optional kind/tracking_number/carrier/line_id/notes
     */
    public function uploadDocument(
        VendorPortalToken $token,
        int $poId,
        array $file,
        array $meta = [],
        bool $requireUploadedFile = true
    ): PurchaseOrderDocument {
        $po = $this->findMyPo($token, $poId);

        $kind = isset($meta['kind']) && $meta['kind'] !== ''
            ? (string) $meta['kind']
            : PurchaseOrderDocument::KIND_TRACKING;
        if (!in_array($kind, PurchaseOrderDocument::KINDS, true)) {
            throw new InvalidArgumentException("Invalid document kind: {$kind}");
        }

        $lineId = null;
        if (isset($meta['purchase_order_line_id']) && $meta['purchase_order_line_id'] !== '') {
            $lineId = (int) $meta['purchase_order_line_id'];
            $line = $this->poRepo->findLine($lineId);
            if ($line === null || $line->purchase_order_id !== $po->id) {
                throw new InvalidArgumentException("Line {$lineId} does not belong to PO {$po->id}");
            }
        }

        $validated = PortalUploadValidator::validate($file, $requireUploadedFile);

        // Partition uploads under the vendor's id so even if the
        // public/uploads root were directory-listed (misconfig), the
        // listing wouldn't mix vendor uploads with sub or customer ones.
        $alloc = $this->uploadStorage->allocatePath(
            $token->vendor_id,
            $validated['extension']
        );
        try {
            $this->uploadStorage->persist($validated['tmp_name'], $alloc['abs_path']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not persist document upload: ' . $e->getMessage(), 0, $e);
        }

        $tracking = isset($meta['tracking_number']) && $meta['tracking_number'] !== ''
            ? substr((string) $meta['tracking_number'], 0, 120)
            : null;
        $carrier = isset($meta['carrier']) && $meta['carrier'] !== ''
            ? substr((string) $meta['carrier'], 0, 80)
            : null;
        $notes = isset($meta['notes']) && trim((string) $meta['notes']) !== ''
            ? substr(trim((string) $meta['notes']), 0, 4000)
            : null;

        return $this->docRepo->create([
            'purchase_order_id' => $po->id,
            'purchase_order_line_id' => $lineId,
            'kind' => $kind,
            'original_name' => $validated['original_name'],
            'stored_path' => $alloc['rel_path'],
            'mime_type' => $validated['mime_type'],
            'size_bytes' => $validated['size'],
            'sha256' => $validated['sha256'],
            'tracking_number' => $tracking,
            'carrier' => $carrier,
            'notes' => $notes,
            'uploaded_via_token_id' => $token->id,
        ]);
    }

    /**
     * Soft-delete a document the vendor uploaded themselves (within their PO).
     * The file on disk is left in place; a separate cleanup job purges by
     * deleted_at age.
     */
    public function deleteOwnDocument(VendorPortalToken $token, int $documentId): void
    {
        $doc = $this->docRepo->find($documentId);
        if ($doc === null || $doc->deleted_at !== null) {
            throw new InvalidArgumentException('Document not available to this token');
        }
        // Only documents uploaded via THIS vendor's token are deletable from
        // the portal — staff-uploaded docs (e.g., scanned packing slip) stay.
        if ($doc->uploaded_via_token_id === null) {
            throw new InvalidArgumentException('Document not available to this token');
        }
        $owningToken = $this->tokenRepo->findById($doc->uploaded_via_token_id);
        if ($owningToken === null || $owningToken->vendor_id !== $token->vendor_id) {
            throw new InvalidArgumentException('Document not available to this token');
        }
        $this->docRepo->softDelete($documentId);
    }

    // ──────────────────────────────────── staff-side document listing ────

    /**
     * Staff read of all documents for a PO. Used by the existing
     * procurement staff UI to surface what the vendor uploaded.
     *
     * @return array<int, PurchaseOrderDocument>
     */
    public function listDocumentsForStaff(User $actor, int $poId): array
    {
        $this->gate->assert($actor, 'procurement.view');
        $po = $this->poRepo->findHeader($poId);
        if ($po === null) {
            throw new InvalidArgumentException("Purchase order {$poId} not found");
        }
        return $this->docRepo->listForPurchaseOrder($poId);
    }

    // ──────────────────────────────────────────────────── helpers ───────────

    /**
     * 32-byte URL-safe random secret. Caller prefixes "ven_" only at the
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
