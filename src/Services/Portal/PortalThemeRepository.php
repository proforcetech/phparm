<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalTheme;
use PDO;

/**
 * Phase 6.8 of docs/expansion-plan.md — data access for portal_themes.
 *
 * Upsert-by-company is the primary write path: there is at most one row
 * per company (UNIQUE company_id). Lookup by host (subdomain / domain)
 * is how pre-auth theme resolution runs on the public /api/portal/theme
 * endpoint so that login pages can render branded before any JWT exists.
 */
class PortalThemeRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByCompany(int $companyId): ?PortalTheme
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_themes WHERE company_id = :co LIMIT 1'
        );
        $stmt->execute(['co' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findBySubdomain(string $subdomain): ?PortalTheme
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_themes WHERE custom_subdomain = :sd AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['sd' => $subdomain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByDomain(string $domain): ?PortalTheme
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_themes WHERE custom_domain = :dn AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['dn' => $domain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function upsert(int $companyId, array $fields, int $updatedByUserId): int
    {
        $existing = $this->findByCompany($companyId);
        if ($existing === null) {
            return $this->insertRow($companyId, $fields, $updatedByUserId);
        }
        $this->updateRow($existing->id, $fields, $updatedByUserId);
        return $existing->id;
    }

    public function deleteByCompany(int $companyId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM portal_themes WHERE company_id = :co'
        );
        $stmt->execute(['co' => $companyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertRow(int $companyId, array $fields, int $updatedByUserId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_themes
                (company_id, display_name,
                 custom_subdomain, custom_domain,
                 primary_color, secondary_color, accent_color,
                 background_color, text_color,
                 logo_url, favicon_url, email_logo_url,
                 email_from_name, email_from_address, email_reply_to,
                 support_phone, support_email, support_url,
                 footer_text, is_active, updated_by_user_id,
                 created_at, updated_at)
             VALUES
                (:co, :dn,
                 :sub, :dom,
                 :pc, :sc, :ac,
                 :bg, :tc,
                 :lu, :fu, :eu,
                 :efn, :efa, :ert,
                 :sp, :se, :su,
                 :ft, :ia, :by,
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute($this->bindParams($companyId, $fields, $updatedByUserId));
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateRow(int $id, array $fields, int $updatedByUserId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_themes SET
                display_name = :dn,
                custom_subdomain = :sub,
                custom_domain = :dom,
                primary_color = :pc,
                secondary_color = :sc,
                accent_color = :ac,
                background_color = :bg,
                text_color = :tc,
                logo_url = :lu,
                favicon_url = :fu,
                email_logo_url = :eu,
                email_from_name = :efn,
                email_from_address = :efa,
                email_reply_to = :ert,
                support_phone = :sp,
                support_email = :se,
                support_url = :su,
                footer_text = :ft,
                is_active = :ia,
                updated_by_user_id = :by,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $params = $this->bindParams(0, $fields, $updatedByUserId);
        unset($params['co']);
        $params['id'] = $id;
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function bindParams(int $companyId, array $fields, int $updatedByUserId): array
    {
        return [
            'co' => $companyId,
            'dn' => $fields['display_name'],
            'sub' => $fields['custom_subdomain'] ?? null,
            'dom' => $fields['custom_domain'] ?? null,
            'pc' => $fields['primary_color'] ?? null,
            'sc' => $fields['secondary_color'] ?? null,
            'ac' => $fields['accent_color'] ?? null,
            'bg' => $fields['background_color'] ?? null,
            'tc' => $fields['text_color'] ?? null,
            'lu' => $fields['logo_url'] ?? null,
            'fu' => $fields['favicon_url'] ?? null,
            'eu' => $fields['email_logo_url'] ?? null,
            'efn' => $fields['email_from_name'] ?? null,
            'efa' => $fields['email_from_address'] ?? null,
            'ert' => $fields['email_reply_to'] ?? null,
            'sp' => $fields['support_phone'] ?? null,
            'se' => $fields['support_email'] ?? null,
            'su' => $fields['support_url'] ?? null,
            'ft' => $fields['footer_text'] ?? null,
            'ia' => (int) ($fields['is_active'] ?? 1),
            'by' => $updatedByUserId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PortalTheme
    {
        $t = new PortalTheme();
        $t->id = (int) $row['id'];
        $t->company_id = (int) $row['company_id'];
        $t->display_name = (string) $row['display_name'];
        $t->custom_subdomain = $row['custom_subdomain'] !== null ? (string) $row['custom_subdomain'] : null;
        $t->custom_domain = $row['custom_domain'] !== null ? (string) $row['custom_domain'] : null;
        $t->primary_color = $row['primary_color'] !== null ? (string) $row['primary_color'] : null;
        $t->secondary_color = $row['secondary_color'] !== null ? (string) $row['secondary_color'] : null;
        $t->accent_color = $row['accent_color'] !== null ? (string) $row['accent_color'] : null;
        $t->background_color = $row['background_color'] !== null ? (string) $row['background_color'] : null;
        $t->text_color = $row['text_color'] !== null ? (string) $row['text_color'] : null;
        $t->logo_url = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
        $t->favicon_url = $row['favicon_url'] !== null ? (string) $row['favicon_url'] : null;
        $t->email_logo_url = $row['email_logo_url'] !== null ? (string) $row['email_logo_url'] : null;
        $t->email_from_name = $row['email_from_name'] !== null ? (string) $row['email_from_name'] : null;
        $t->email_from_address = $row['email_from_address'] !== null ? (string) $row['email_from_address'] : null;
        $t->email_reply_to = $row['email_reply_to'] !== null ? (string) $row['email_reply_to'] : null;
        $t->support_phone = $row['support_phone'] !== null ? (string) $row['support_phone'] : null;
        $t->support_email = $row['support_email'] !== null ? (string) $row['support_email'] : null;
        $t->support_url = $row['support_url'] !== null ? (string) $row['support_url'] : null;
        $t->footer_text = $row['footer_text'] !== null ? (string) $row['footer_text'] : null;
        $t->is_active = (int) $row['is_active'];
        $t->updated_by_user_id = (int) $row['updated_by_user_id'];
        $t->created_at = $row['created_at'] ?? null;
        $t->updated_at = $row['updated_at'] ?? null;
        return $t;
    }
}
