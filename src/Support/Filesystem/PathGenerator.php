<?php

namespace App\Support\Filesystem;

use RuntimeException;

class PathGenerator
{
    private array $categories;

    public function __construct(array $categories)
    {
        $this->categories = $categories;
    }

    public function forCategory(string $category, string $originalName): string
    {
        $definition = $this->definition($category);
        $folder = $definition['folder'];

        $safeName = $this->sanitizeFilename($originalName);
        $timestamp = date('YmdHis');

        return trim($folder, '/') . '/' . $timestamp . '_' . $safeName;
    }

    public function definition(string $category): array
    {
        $definition = $this->categories[$category] ?? null;
        if ($definition === null) {
            throw new RuntimeException("Unknown upload category: {$category}");
        }

        if (is_string($definition)) {
            $definition = ['folder' => $definition];
        }

        return [
            'folder' => $definition['folder'] ?? $definition['path'] ?? $category,
            'disk' => $definition['disk'] ?? null,
            'visibility' => $definition['visibility'] ?? 'public',
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = strtolower($filename);
        $filename = preg_replace('/[^a-z0-9._-]/', '_', $filename) ?? $filename;
        $filename = trim($filename, '_');

        if ($filename === '') {
            $filename = 'upload';
        }

        return $filename;
    }
}
