<?php

namespace App\Services\CMS;

use App\Database\Connection;

class CMSIndexService
{
    private Connection $connection;
    private string $tablePrefix;
    private string $table;

    public function __construct(Connection $connection, string $tablePrefix = 'cms_')
    {
        $this->connection = $connection;
        $this->tablePrefix = $tablePrefix;
        $this->table = $tablePrefix . 'search_index';
    }

    /**
     * @param array<string, mixed> $page
     */
    public function indexPage(array $page): void
    {
        if (empty($page['id'])) {
            return;
        }

        $content = $this->buildContent([
            $page['title'] ?? '',
            $page['summary'] ?? '',
            $page['content'] ?? '',
            $page['meta_title'] ?? '',
            $page['meta_description'] ?? '',
            $page['meta_keywords'] ?? '',
        ]);

        $this->upsert(
            'page',
            (int) $page['id'],
            (string) ($page['title'] ?? ''),
            $page['slug'] ?? null,
            $page['summary'] ?? null,
            $content,
            $page['status'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $component
     */
    public function indexComponent(array $component): void
    {
        if (empty($component['id'])) {
            return;
        }

        $content = $this->buildContent([
            $component['name'] ?? '',
            $component['description'] ?? '',
            $component['content'] ?? '',
            $component['css'] ?? '',
            $component['javascript'] ?? '',
        ]);

        $status = !empty($component['is_active']) ? 'active' : 'inactive';

        $this->upsert(
            'component',
            (int) $component['id'],
            (string) ($component['name'] ?? ''),
            $component['slug'] ?? null,
            $component['description'] ?? null,
            $content,
            $status
        );
    }

    public function deleteEntry(string $sourceType, int $sourceId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            "DELETE FROM {$this->table} WHERE source_type = :source_type AND source_id = :source_id"
        );

        $stmt->execute([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function reindexAll(): array
    {
        $pdo = $this->connection->pdo();

        $pdo->prepare("DELETE FROM {$this->table} WHERE source_type IN ('page', 'component')")
            ->execute();

        $pageRows = $pdo->query(
            "SELECT id, title, slug, summary, content, meta_title, meta_description, meta_keywords, status
            FROM {$this->tablePrefix}pages"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $componentRows = $pdo->query(
            "SELECT id, name, slug, description, content, css, javascript, is_active
            FROM {$this->tablePrefix}components"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($pageRows as $page) {
            $this->indexPage($page);
        }

        foreach ($componentRows as $component) {
            $this->indexComponent($component);
        }

        return [
            'pages' => count($pageRows),
            'components' => count($componentRows),
        ];
    }

    /**
     * @param array<int, mixed> $parts
     */
    private function buildContent(array $parts): string
    {
        $joined = implode(' ', array_filter(array_map('strval', $parts), static fn (string $part) => $part !== ''));
        $decoded = html_entity_decode(strip_tags($joined), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $decoded);

        return trim((string) $normalized);
    }

    private function upsert(
        string $sourceType,
        int $sourceId,
        string $title,
        ?string $slug,
        ?string $summary,
        string $content,
        ?string $status
    ): void {
        $stmt = $this->connection->pdo()->prepare(
            "INSERT INTO {$this->table} (source_type, source_id, title, slug, summary, content, status, updated_at)
            VALUES (:source_type, :source_id, :title, :slug, :summary, :content, :status, NOW())
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                slug = VALUES(slug),
                summary = VALUES(summary),
                content = VALUES(content),
                status = VALUES(status),
                updated_at = VALUES(updated_at)"
        );

        $stmt->execute([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'content' => $content,
            'status' => $status,
        ]);
    }
}
