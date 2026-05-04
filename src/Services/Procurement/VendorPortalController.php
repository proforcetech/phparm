<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDocument;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPortalToken;
use InvalidArgumentException;

/**
 * Phase 18 / C1 — HTTP facade for the vendor self-service portal.
 *
 * Two surfaces:
 *   - Staff endpoints (issue / list / revoke tokens, view docs) — receive
 *     a User actor.
 *   - Vendor-portal endpoints — receive a VendorPortalToken (the route
 *     layer authenticates the bearer token before calling these).
 *
 * Token plaintext is returned exactly once (issueToken) so the staff user
 * who created it can copy it to the vendor. The DB never stores plaintext.
 */
class VendorPortalController
{
    public function __construct(private readonly VendorPortalService $service)
    {
    }

    // ─────────────────────────────────────── staff token management ────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function issueToken(User $actor, int $vendorId, array $payload): array
    {
        $label = isset($payload['label']) && $payload['label'] !== ''
            ? (string) $payload['label']
            : null;
        $expires = isset($payload['expires_at']) && $payload['expires_at'] !== ''
            ? (string) $payload['expires_at']
            : null;
        $issued = $this->service->issueToken($actor, $vendorId, $label, $expires);
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
    public function listTokens(User $actor, int $vendorId, bool $includeRevoked = false): array
    {
        $items = $this->service->listTokens($actor, $vendorId, $includeRevoked);
        return ['data' => array_map(self::tokenToArray(...), $items)];
    }

    public function revokeToken(User $actor, int $tokenId, ?string $reason = null): void
    {
        $this->service->revokeToken($actor, $tokenId, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function listPoDocumentsForStaff(User $actor, int $poId): array
    {
        $items = $this->service->listDocumentsForStaff($actor, $poId);
        return ['data' => array_map(self::documentToArray(...), $items)];
    }

    // ─────────────────────────────────────────── self-service surface ──

    /**
     * @return array<string, mixed>
     */
    public function me(VendorPortalToken $token, Vendor $vendor): array
    {
        return [
            'data' => [
                'vendor' => self::vendorToPublicArray($vendor),
                'token' => self::tokenToArray($token),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMyPos(VendorPortalToken $token, ?string $status = null): array
    {
        $items = $this->service->listMyPos($token, $status);
        return ['data' => array_map(self::poToVendorArray(...), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMyPo(VendorPortalToken $token, int $poId): array
    {
        $detail = $this->service->getMyPo($token, $poId);
        return [
            'data' => [
                'po' => self::poToVendorArray($detail['po']),
                'lines' => array_map(self::lineToVendorArray(...), $detail['lines']),
                'documents' => array_map(self::documentToArray(...), $detail['documents']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledgePo(VendorPortalToken $token, int $poId): array
    {
        $po = $this->service->acknowledgePo($token, $poId);
        return ['data' => self::poToVendorArray($po)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function markLineShipped(
        VendorPortalToken $token,
        int $lineId,
        array $payload
    ): array {
        $line = $this->service->markLineShipped($token, $lineId, $payload);
        return ['data' => self::lineToVendorArray($line)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMyDocuments(VendorPortalToken $token, int $poId): array
    {
        $items = $this->service->listDocuments($token, $poId);
        return ['data' => array_map(self::documentToArray(...), $items)];
    }

    /**
     * @param array<string, mixed> $file $_FILES-style entry
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function uploadDocument(
        VendorPortalToken $token,
        int $poId,
        array $file,
        array $body
    ): array {
        $doc = $this->service->uploadDocument($token, $poId, $file, $body);
        return ['data' => self::documentToArray($doc)];
    }

    public function deleteOwnDocument(VendorPortalToken $token, int $documentId): void
    {
        $this->service->deleteOwnDocument($token, $documentId);
    }

    // ─────────────────────────────────────────────── serializers ──────

    /**
     * Token serializer never includes the hash — the hash IS the
     * secret-equivalent and must not leave the server.
     *
     * @return array<string, mixed>
     */
    private static function tokenToArray(VendorPortalToken $t): array
    {
        return [
            'id' => $t->id,
            'vendor_id' => $t->vendor_id,
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
     * Vendor serializer for the portal: scrub fields the vendor doesn't
     * need to see (internal notes, our 1099 flag, our payment_terms — those
     * are *our* commercial state, not theirs).
     *
     * @return array<string, mixed>
     */
    private static function vendorToPublicArray(Vendor $v): array
    {
        return [
            'id' => $v->id,
            'name' => $v->name,
            'code' => $v->code,
            'status' => $v->status,
            'primary_contact_name' => $v->primary_contact_name,
            'email' => $v->email,
            'phone' => $v->phone,
            'website' => $v->website,
        ];
    }

    /**
     * PO serializer for the vendor view: scrub fields that are *our*
     * commercial state (markup_pct, customer_id, internal notes when set).
     * Vendor sees the order they need to fulfill, not our margin.
     *
     * @return array<string, mixed>
     */
    private static function poToVendorArray(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'vendor_id' => $po->vendor_id,
            'status' => $po->status,
            'currency' => $po->currency,
            'is_consigned' => $po->is_consigned,
            'subtotal_cents' => $po->subtotal_cents,
            'tax_cents' => $po->tax_cents,
            'shipping_cents' => $po->shipping_cents,
            'total_cents' => $po->total_cents,
            'notes' => $po->notes,
            'ordered_at' => $po->ordered_at,
            'expected_at' => $po->expected_at,
            'vendor_acknowledged_at' => $po->vendor_acknowledged_at,
            'received_at' => $po->received_at,
            'closed_at' => $po->closed_at,
            'cancelled_at' => $po->cancelled_at,
            'cancel_reason' => $po->cancel_reason,
            'created_at' => $po->created_at,
            'updated_at' => $po->updated_at,
        ];
    }

    /**
     * Line serializer for the vendor view. Includes ordered/received qty,
     * unit_cost (the vendor knows what they're charging us), and the new
     * shipment-tracking fields. Internal markup is not relevant per-line.
     *
     * @return array<string, mixed>
     */
    private static function lineToVendorArray(PurchaseOrderLine $line): array
    {
        return [
            'id' => $line->id,
            'purchase_order_id' => $line->purchase_order_id,
            'line_number' => $line->line_number,
            'description' => $line->description,
            'sku' => $line->sku,
            'quantity_ordered' => $line->quantity_ordered,
            'quantity_received' => $line->quantity_received,
            'unit_cost_cents' => $line->unit_cost_cents,
            'tax_cents' => $line->tax_cents,
            'line_total_cents' => $line->line_total_cents,
            'status' => $line->status,
            'notes' => $line->notes,
            'vendor_shipped_at' => $line->vendor_shipped_at,
            'vendor_tracking_number' => $line->vendor_tracking_number,
            'vendor_carrier' => $line->vendor_carrier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentToArray(PurchaseOrderDocument $d): array
    {
        return [
            'id' => $d->id,
            'purchase_order_id' => $d->purchase_order_id,
            'purchase_order_line_id' => $d->purchase_order_line_id,
            'kind' => $d->kind,
            'original_name' => $d->original_name,
            'stored_path' => $d->stored_path,
            'mime_type' => $d->mime_type,
            'size_bytes' => $d->size_bytes,
            'tracking_number' => $d->tracking_number,
            'carrier' => $d->carrier,
            'notes' => $d->notes,
            'uploaded_via_token_id' => $d->uploaded_via_token_id,
            'uploaded_by_user_id' => $d->uploaded_by_user_id,
            'uploaded_at' => $d->uploaded_at,
        ];
    }
}
