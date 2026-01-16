<?php

namespace App\Services\CMS;

use App\Database\Connection;
use PDO;

class CMSComponentUsageService
{
    private Connection $connection;
    private string $tablePrefix;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->tablePrefix = env('CMS_TABLE_PREFIX', 'cms_');
    }

    /**
     * @param array<string, mixed> $pageData
     */
    public function syncForPage(int $pageId, array $pageData): void
    {
        $componentIds = $this->resolveComponentIds($pageData);
        $pdo = $this->connection->pdo();

        if (empty($componentIds)) {
            $stmt = $pdo->prepare("DELETE FROM {$this->table('component_page_usage')} WHERE page_id = :page_id");
            $stmt->execute(['page_id' => $pageId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($componentIds), '?'));

        $delete = $pdo->prepare(
            "DELETE FROM {$this->table('component_page_usage')}
            WHERE page_id = ? AND component_id NOT IN ({$placeholders})"
        );
        $delete->execute(array_merge([$pageId], $componentIds));

        $existingStmt = $pdo->prepare(
            "SELECT component_id FROM {$this->table('component_page_usage')} WHERE page_id = ?"
        );
        $existingStmt->execute([$pageId]);
        $existing = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

        $missing = array_values(array_diff($componentIds, $existing));
        if (empty($missing)) {
            return;
        }

        $insert = $pdo->prepare(
            "INSERT IGNORE INTO {$this->table('component_page_usage')} (page_id, component_id) VALUES (?, ?)"
        );

        foreach ($missing as $componentId) {
            $insert->execute([$pageId, $componentId]);
        }
    }

    public function clearForPage(int $pageId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            "DELETE FROM {$this->table('component_page_usage')} WHERE page_id = :page_id"
        );
        $stmt->execute(['page_id' => $pageId]);
    }

    /**
     * @return array<int, string>
     */
    public function findPageSlugsForComponent(int $componentId): array
    {
        $stmt = $this->connection->pdo()->prepare("
            SELECT pages.slug
            FROM {$this->table('component_page_usage')} usage
            INNER JOIN {$this->table('pages')} pages ON pages.id = usage.page_id
            WHERE usage.component_id = :component_id
        ");
        $stmt->execute(['component_id' => $componentId]);

        return array_values(array_unique(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * @param array<string, mixed> $pageData
     * @return array<int, int>
     */
    private function resolveComponentIds(array $pageData): array
    {
        $componentIds = [];

        if (!empty($pageData['header_component_id'])) {
            $componentIds[] = (int) $pageData['header_component_id'];
        }

        if (!empty($pageData['footer_component_id'])) {
            $componentIds[] = (int) $pageData['footer_component_id'];
        }

        $slugs = $this->extractComponentSlugs((string) ($pageData['content'] ?? ''));
        $templateId = $pageData['template_id'] ?? null;
        if (!empty($templateId)) {
            $templateStmt = $this->connection->pdo()->prepare(
                "SELECT structure FROM {$this->table('templates')} WHERE id = :id LIMIT 1"
            );
            $templateStmt->execute(['id' => $templateId]);
            $structure = $templateStmt->fetchColumn();
            if ($structure) {
                $slugs = array_merge($slugs, $this->extractComponentSlugs((string) $structure));
            }
        }

        $slugs = array_values(array_unique(array_filter($slugs)));
        if (!empty($slugs)) {
            $placeholders = implode(',', array_fill(0, count($slugs), '?'));
            $stmt = $this->connection->pdo()->prepare(
                "SELECT id FROM {$this->table('components')} WHERE slug IN ({$placeholders})"
            );
            $stmt->execute($slugs);
            $componentIds = array_merge($componentIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        $componentIds = array_values(array_unique(array_filter($componentIds)));
        sort($componentIds);

        return $componentIds;
    }

    /**
     * @return array<int, string>
     */
    private function extractComponentSlugs(string $content): array
    {
        if ($content === '') {
            return [];
        }

        if (!preg_match_all('/\{\{component:([a-zA-Z0-9_-]+)\}\}/', $content, $matches)) {
            return [];
        }

        return $matches[1] ?? [];
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
