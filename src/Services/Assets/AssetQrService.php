<?php

namespace App\Services\Assets;

use App\Models\SiteAsset;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

/**
 * Generates public QR codes for installed assets (Phase 2.3 of
 * docs/expansion-plan.md).
 *
 * Each asset gets an opaque hex token minted on demand and persisted on its
 * row. The PNG encodes a URL like `{baseUrl}/api/qr/scan/{token}`, so a tech
 * or customer with a scanner lands on the public summary without any prior
 * auth.
 */
class AssetQrService
{
    public function __construct(
        private readonly SiteAssetRepository $assets,
        private readonly string $publicBaseUrl,
    ) {
    }

    /**
     * Returns the opaque token for an asset, minting one if necessary. Safe
     * to call multiple times — the first call lazy-persists the token.
     */
    public function tokenForAsset(SiteAsset $asset): string
    {
        if ($asset->qr_token !== null && $asset->qr_token !== '') {
            return $asset->qr_token;
        }
        $token = bin2hex(random_bytes(24));
        $this->assets->setQrToken($asset->id, $token);
        $asset->qr_token = $token;
        return $token;
    }

    public function scanUrlForToken(string $token): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/api/qr/scan/' . $token;
    }

    /**
     * Renders a QR PNG as raw binary bytes.
     */
    public function renderPng(string $token, int $size = 8): string
    {
        $size = max(2, min(20, $size));
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => $size,
            'imageBase64' => false,
            'outputBase64' => false,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);
        $payload = $this->scanUrlForToken($token);
        $png = (new QRCode($options))->render($payload);
        if (!is_string($png) || $png === '') {
            throw new RuntimeException('Failed to render QR PNG');
        }
        return $png;
    }
}
