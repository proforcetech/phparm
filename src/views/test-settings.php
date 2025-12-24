<?php
/**
 * Diagnostic script to test settings API and identify issues
 */

require __DIR__ . '/bootstrap.php';

use App\Database\Connection;

echo "=== Testing Settings Configuration ===\n\n";

// Test 1: Database connection
echo "1. Testing database connection...\n";
try {
    $connection = new Connection($config['database']);
    $pdo = $connection->pdo();
    echo "   ✓ Database connection successful\n\n";
} catch (Exception $e) {
    echo "   ✗ Database connection FAILED: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Check if settings table exists
echo "2. Checking if settings table exists...\n";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    $exists = $stmt->rowCount() > 0;

    if ($exists) {
        echo "   ✓ Settings table exists\n\n";
    } else {
        echo "   ✗ Settings table does NOT exist!\n";
        echo "   → Run migrations to create the table\n\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "   ✗ Error checking table: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 3: Check settings data
echo "3. Checking settings data...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    $count = $stmt->fetchColumn();
    echo "   ✓ Found {$count} settings in database\n\n";
} catch (Exception $e) {
    echo "   ✗ Error querying settings: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 4: Check for invalid types
echo "4. Checking for invalid setting types...\n";
try {
    $stmt = $pdo->query("SELECT `key`, `type`, `value` FROM settings");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $validTypes = ['string', 'integer', 'float', 'boolean', 'json'];
    $invalidSettings = [];

    foreach ($settings as $setting) {
        if (!in_array($setting['type'], $validTypes, true)) {
            $invalidSettings[] = $setting;
        }
    }

    if (empty($invalidSettings)) {
        echo "   ✓ All settings have valid types\n\n";
    } else {
        echo "   ✗ Found " . count($invalidSettings) . " settings with INVALID types:\n";
        foreach ($invalidSettings as $setting) {
            echo "      - Key: {$setting['key']}, Type: {$setting['type']}\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error checking types: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 5: Try to fetch all settings using SettingsRepository
echo "5. Testing SettingsRepository::all()...\n";
try {
    $settingsRepo = new App\Support\SettingsRepository($connection);
    $allSettings = $settingsRepo->all();

    echo "   ✓ Successfully fetched " . count($allSettings) . " settings\n";
    echo "   Settings keys: " . implode(', ', array_keys($allSettings)) . "\n\n";
} catch (Exception $e) {
    echo "   ✗ Error fetching settings: " . $e->getMessage() . "\n";
    echo "   Stack trace:\n" . $e->getTraceAsString() . "\n\n";
    exit(1);
}

echo "=== All Tests Passed ===\n";
