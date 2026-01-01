<?php
/**
 * Test script to debug inventory search functionality
 * Run with: php test-inventory-search.php
 */

$config = require_once __DIR__ . '/bootstrap.php';

use App\Database\Connection;
use App\Services\Inventory\InventoryItemRepository;

// Create database connection using config
$connection = new Connection($config['database']);

// Create repository
$repository = new InventoryItemRepository($connection);

// Test search query
$searchQuery = 'D2224';
echo "Testing inventory search for query: '{$searchQuery}'\n";
echo str_repeat('=', 60) . "\n";

// Test 1: Direct database query
echo "\n1. Direct SQL Query Test:\n";
try {
    $stmt = $connection->pdo()->prepare("SELECT id, name, sku, description FROM inventory_items WHERE sku = :sku LIMIT 1");
    $stmt->execute(['sku' => $searchQuery]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo "✓ Found item with exact SKU match:\n";
        echo "  ID: {$result['id']}\n";
        echo "  Name: {$result['name']}\n";
        echo "  SKU: {$result['sku']}\n";
        echo "  Description: " . ($result['description'] ?: 'N/A') . "\n";
    } else {
        echo "✗ No item found with exact SKU '{$searchQuery}'\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Test 2: LIKE query (as used in search)
echo "\n2. LIKE Query Test (simulating search):\n";
try {
    $stmt = $connection->pdo()->prepare("
        SELECT id, name, sku, description
        FROM inventory_items
        WHERE name LIKE :query
           OR sku LIKE :query
           OR description LIKE :query
        LIMIT 10
    ");
    $stmt->execute(['query' => "%{$searchQuery}%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($results)) {
        echo "✓ Found " . count($results) . " item(s) with LIKE query:\n";
        foreach ($results as $result) {
            echo "  - ID: {$result['id']}, SKU: {$result['sku']}, Name: {$result['name']}\n";
        }
    } else {
        echo "✗ No items found with LIKE query\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Test 3: Repository searchForParts method
echo "\n3. Repository searchForParts Method Test:\n";
try {
    $items = $repository->searchForParts($searchQuery, null, 10);

    if (!empty($items)) {
        echo "✓ searchForParts returned " . count($items) . " item(s):\n";
        foreach ($items as $item) {
            $data = $item->toArray();
            echo "  - ID: {$data['id']}, SKU: {$data['sku']}, Name: {$data['name']}\n";
        }
    } else {
        echo "✗ searchForParts returned empty array\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
}

// Test 4: Check for manufacturer_part_number column
echo "\n4. Database Schema Check:\n";
try {
    $stmt = $connection->pdo()->query("SHOW COLUMNS FROM inventory_items");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "✓ inventory_items table columns:\n";
    $hasManufacturerPart = in_array('manufacturer_part_number', $columns);
    echo "  - manufacturer_part_number column exists: " . ($hasManufacturerPart ? 'YES' : 'NO') . "\n";
    echo "  - Total columns: " . count($columns) . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Test 5: Check for any whitespace or encoding issues
echo "\n5. Data Quality Check:\n";
try {
    $stmt = $connection->pdo()->prepare("
        SELECT sku, LENGTH(sku) as len, HEX(sku) as hex_value
        FROM inventory_items
        WHERE sku = :sku
           OR TRIM(sku) = :sku
           OR sku LIKE :like_query
        LIMIT 5
    ");
    $stmt->execute([
        'sku' => $searchQuery,
        'like_query' => "%{$searchQuery}%"
    ]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($results)) {
        echo "✓ SKU data analysis:\n";
        foreach ($results as $result) {
            $expectedLen = strlen($searchQuery);
            $hasWhitespace = $result['len'] != $expectedLen;
            echo "  - SKU: '{$result['sku']}' (Length: {$result['len']}" . ($hasWhitespace ? " - WHITESPACE DETECTED!" : "") . ")\n";
            echo "    HEX: {$result['hex_value']}\n";
        }
    } else {
        echo "✗ No matching SKUs found for analysis\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Test complete!\n";
