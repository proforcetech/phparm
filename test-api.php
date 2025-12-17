<?php
/**
 * Diagnostic script to test CMS API endpoint
 */

require __DIR__ . '/bootstrap.php';

use App\Database\Connection;
use App\CMS\Controllers\PageController;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;
use App\Services\CMS\CMSCacheService;

$connection = new Connection($config['database']);
$cmsConfig = $config['cms'] ?? [];
$cmsCache = new CMSCacheService($cmsConfig);
$authConfig = $config['auth'] ?? [];
$gate = new AccessGate(new RolePermissions($authConfig['roles'] ?? []));

$controller = new PageController($connection, $gate, $cmsCache);

$slug = 'about-us';

echo "=== Testing CMS Page Rendering ===\n\n";

// Test 1: Check if page exists
echo "1. Checking if page exists in database...\n";
$page = $controller->publishedPage($slug);
if ($page) {
    echo "   ✓ Page found: {$page['title']}\n";
    echo "   - Status: {$page['status']}\n";
    echo "   - Template ID: " . ($page['template_id'] ?? 'none') . "\n";
    echo "   - Content preview: " . substr($page['content'] ?? '', 0, 100) . "...\n\n";
} else {
    echo "   ✗ Page NOT found in database!\n\n";
    exit(1);
}

// Test 2: Try to render the page
echo "2. Attempting to render page...\n";
try {
    $html = $controller->renderPublishedPage($slug);
    if ($html) {
        echo "   ✓ Page rendered successfully\n";
        echo "   - HTML length: " . strlen($html) . " bytes\n";
        echo "   - Contains {{component: " . (strpos($html, '{{component:') !== false ? 'YES (NOT PROCESSED!)' : 'NO (Good)') . "\n";
        echo "   - First 200 chars: " . substr($html, 0, 200) . "...\n\n";
    } else {
        echo "   ✗ Rendering returned NULL\n\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error during rendering: " . $e->getMessage() . "\n";
    echo "   Stack trace:\n" . $e->getTraceAsString() . "\n\n";
}

echo "=== Test Complete ===\n";
