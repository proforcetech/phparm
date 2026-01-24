<?php

/**
 * Media processing configuration.
 *
 * Controls image variant generation, quality settings, and storage.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Responsive Image Widths
    |--------------------------------------------------------------------------
    |
    | Array of widths (in pixels) for responsive image variants.
    | Variants are only created if the original image is wider than the target.
    |
    */
    'responsive_widths' => [320, 640, 960, 1280, 1600],

    /*
    |--------------------------------------------------------------------------
    | Image Quality Settings
    |--------------------------------------------------------------------------
    |
    | Quality settings for different image formats.
    | JPEG/WebP: 0-100 (higher = better quality, larger file)
    | PNG: 0-9 (higher = more compression, smaller file)
    |
    */
    'quality' => [
        'jpeg' => (int) ($_ENV['MEDIA_JPEG_QUALITY'] ?? 82),
        'webp' => (int) ($_ENV['MEDIA_WEBP_QUALITY'] ?? 82),
        'png_compression' => (int) ($_ENV['MEDIA_PNG_COMPRESSION'] ?? 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | WebP Conversion
    |--------------------------------------------------------------------------
    |
    | Whether to automatically generate WebP variants of images.
    | Requires PHP GD extension with WebP support.
    |
    */
    'webp_enabled' => (bool) ($_ENV['MEDIA_WEBP_ENABLED'] ?? true),

    /*
    |--------------------------------------------------------------------------
    | Upload Settings
    |--------------------------------------------------------------------------
    |
    | Maximum upload size and allowed file types.
    |
    */
    'max_upload_size' => (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760), // 10MB default

    'allowed_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Base path for uploaded media files.
    | Relative to the project root.
    |
    */
    'storage_path' => $_ENV['MEDIA_STORAGE_PATH'] ?? 'storage/uploads/cms',

    /*
    |--------------------------------------------------------------------------
    | URL Prefix
    |--------------------------------------------------------------------------
    |
    | URL prefix for accessing uploaded media.
    | Used when generating public URLs for media files.
    |
    */
    'url_prefix' => $_ENV['MEDIA_URL_PREFIX'] ?? '/storage/uploads/cms',

    /*
    |--------------------------------------------------------------------------
    | Preserve Original
    |--------------------------------------------------------------------------
    |
    | Whether to keep the original uploaded file in addition to variants.
    | If false, only resized variants are kept.
    |
    */
    'preserve_original' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto-Orient Images
    |--------------------------------------------------------------------------
    |
    | Automatically rotate images based on EXIF orientation data.
    | Helps with photos from mobile devices that may have incorrect rotation.
    |
    */
    'auto_orient' => true,
];
