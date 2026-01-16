<?php

namespace App\Services\CMS;

class CMSAssetBundler
{
    private string $assetRoot;
    private string $cacheRoot;
    private string $publicPrefix;

    public function __construct(string $assetRoot, string $cacheRoot, string $publicPrefix = '/cms/assets')
    {
        $this->assetRoot = rtrim($assetRoot, '/');
        $this->cacheRoot = rtrim($cacheRoot, '/');
        $this->publicPrefix = rtrim($publicPrefix, '/');
    }

    /**
     * @param array<int, string> $cssAssets
     * @param array<int, string> $jsAssets
     * @return array{css: string, js: string}
     */
    public function buildAssetTags(string $pageKey, array $cssAssets, array $jsAssets): array
    {
        $cssAssets = $this->normalizeAssets($cssAssets);
        $jsAssets = $this->normalizeAssets($jsAssets);

        [$cssLocal, $cssExternal] = $this->partitionAssets($cssAssets);
        [$jsLocal, $jsExternal] = $this->partitionAssets($jsAssets);

        $cssBundleUrl = $this->bundleAssets($pageKey, $cssLocal, 'css');
        $jsBundleUrl = $this->bundleAssets($pageKey, $jsLocal, 'js');

        return [
            'css' => $this->renderCssTags($cssExternal, $cssBundleUrl),
            'js' => $this->renderJsTags($jsExternal, $jsBundleUrl),
        ];
    }

    /**
     * @param array<int, string> $assets
     * @return array<int, string>
     */
    private function normalizeAssets(array $assets): array
    {
        $normalized = [];
        $seen = [];

        foreach ($assets as $asset) {
            $asset = trim($asset);
            if ($asset === '') {
                continue;
            }

            if (isset($seen[$asset])) {
                continue;
            }

            $seen[$asset] = true;
            $normalized[] = $asset;
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $assets
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function partitionAssets(array $assets): array
    {
        $local = [];
        $external = [];

        foreach ($assets as $asset) {
            if ($this->isExternalAsset($asset)) {
                $external[] = $asset;
            } else {
                $local[] = ltrim($asset, '/');
            }
        }

        return [$local, $external];
    }

    private function isExternalAsset(string $asset): bool
    {
        return preg_match('#^(https?:)?//#', $asset) === 1 || str_starts_with($asset, '/');
    }

    /**
     * @param array<int, string> $assets
     */
    private function bundleAssets(string $pageKey, array $assets, string $extension): ?string
    {
        if ($assets === []) {
            return null;
        }

        $bundleDir = $this->cacheRoot . '/assets/bundles';
        if (!is_dir($bundleDir) && !mkdir($bundleDir, 0755, true) && !is_dir($bundleDir)) {
            error_log(sprintf('CMS asset bundle directory could not be created: %s', $bundleDir));
            return null;
        }

        $signature = $this->buildSignature($assets);
        $hash = substr(sha1($signature), 0, 12);
        $safePageKey = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $pageKey) ?: 'page';
        $bundleName = sprintf('%s-%s.%s', $safePageKey, $hash, $extension);
        $bundlePath = $bundleDir . '/' . $bundleName;

        if (!file_exists($bundlePath)) {
            $contents = $this->concatenateAssets($assets, $extension);
            if ($contents === null) {
                return null;
            }
            file_put_contents($bundlePath, $contents, LOCK_EX);
        }

        return $this->publicPrefix . '/bundles/' . $bundleName;
    }

    /**
     * @param array<int, string> $assets
     */
    private function buildSignature(array $assets): string
    {
        $signature = [];

        foreach ($assets as $asset) {
            $path = $this->resolveAssetPath($asset);
            $signature[] = [
                'path' => $asset,
                'mtime' => $path ? filemtime($path) : null,
            ];
        }

        $encoded = json_encode($signature);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * @param array<int, string> $assets
     */
    private function concatenateAssets(array $assets, string $extension): ?string
    {
        $output = '';
        $prefix = $extension === 'css' ? '/*' : '//';
        $suffix = $extension === 'css' ? '*/' : '';

        foreach ($assets as $asset) {
            $path = $this->resolveAssetPath($asset);
            if ($path === null) {
                error_log(sprintf('CMS asset bundle missing file: %s', $asset));
                continue;
            }

            $output .= sprintf("%s Source: %s %s\n", $prefix, $asset, $suffix);
            $output .= file_get_contents($path) . "\n";
        }

        return $output === '' ? null : $output;
    }

    private function resolveAssetPath(string $asset): ?string
    {
        $candidate = $this->assetRoot . '/' . ltrim($asset, '/');
        $realPath = realpath($candidate);

        if ($realPath === false) {
            return null;
        }

        $assetRoot = $this->assetRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $assetRoot)) {
            return null;
        }

        return $realPath;
    }

    /**
     * @param array<int, string> $externalAssets
     */
    private function renderCssTags(array $externalAssets, ?string $bundleUrl): string
    {
        $tags = '';

        foreach ($externalAssets as $asset) {
            $tags .= '<link rel="stylesheet" href="' . htmlspecialchars($asset) . '">' . "\n";
        }

        if ($bundleUrl !== null) {
            $tags .= '<link rel="stylesheet" href="' . htmlspecialchars($bundleUrl) . '">' . "\n";
        }

        return $tags;
    }

    /**
     * @param array<int, string> $externalAssets
     */
    private function renderJsTags(array $externalAssets, ?string $bundleUrl): string
    {
        $tags = '';

        foreach ($externalAssets as $asset) {
            $tags .= '<script src="' . htmlspecialchars($asset) . '"></script>' . "\n";
        }

        if ($bundleUrl !== null) {
            $tags .= '<script src="' . htmlspecialchars($bundleUrl) . '"></script>' . "\n";
        }

        return $tags;
    }
}
