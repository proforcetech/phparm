<?php

require_once __DIR__ . '/vendor/autoload.php';

// Manually load .env file
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

use App\Database\Connection;

echo "================================\n";
echo "PHPArm Database Migrations\n";
echo "================================\n\n";

try {
    // Create database connection
    $connection = new Connection([
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'phparm',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        // OPTIONAL: If your Connection class accepts options here, pass it here. 
        // Otherwise, the setAttribute below is fine.
        'options' => [
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    ]);

    $pdo = $connection->pdo();

    // Ensure buffered queries are enabled
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    
    echo "✓ Database connection established\n\n";
    
    // Create migrations table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Get list of executed migrations
    $stmt = $pdo->query("SELECT migration FROM migrations");
    $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor(); // <--- FIX 1: Explicitly close cursor after fetching
    
    // Get list of migration files
    $migrationFiles = glob(__DIR__ . '/database/migrations/*.sql');
    sort($migrationFiles);
    
    $runCount = 0;
    
    foreach ($migrationFiles as $file) {
        $migrationName = basename($file);
        
        // Skip README
        if (strpos($migrationName, 'README') !== false) {
            continue;
        }
        
        // Skip if already executed
        if (in_array($migrationName, $executed)) {
            echo "⊘ Skipped: {$migrationName} (already executed)\n";
            continue;
        }
        
        echo "→ Running: {$migrationName}...";
        
        try {
            $sql = file_get_contents($file);
            
            // Split by semicolons but not inside quotes
            $statements = preg_split('/;(?=(?:[^\'"]|[\'"][^\'"]*[\'"])*$)/', $sql, -1, PREG_SPLIT_NO_EMPTY);
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement) || strpos($statement, '--') === 0) {
                    continue;
                }
                
                // <--- FIX 2: Use prepare/execute/closeCursor instead of exec
                // This ensures that if a migration contains a SELECT or output,
                // the connection is freed immediately.
                $stmt = $pdo->prepare($statement);
                $stmt->execute();
                $stmt->closeCursor(); 
            }
            
            // Record migration
            $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
            $stmt->execute([$migrationName]);
            $stmt->closeCursor(); // Good practice to close here too
            
            echo " ✓\n";
            $runCount++;
            
        } catch (PDOException $e) {
            echo " ✗\n";
            echo "  Error: " . $e->getMessage() . "\n\n";
            
            // Continue with next migration instead of stopping
            continue;
        }
    }
    
    echo "\n================================\n";
    if ($runCount > 0) {
        echo "✓ Migrations completed!\n";
        echo "  Executed: {$runCount} migration(s)\n";
    } else {
        echo "✓ All migrations up to date!\n";
    }
    echo "================================\n\n";
    
} catch (Exception $e) {
    echo "\n✗ Migration failed!\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
