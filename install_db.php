<?php

/**
 * PHPArm Database Installation Script
 *
 * This script installs the database schema from a consolidated SQL file.
 * For new installations only. Use upgrade.php for existing databases.
 */

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
echo "PHPArm Database Installation\n";
echo "================================\n\n";

// Check for --force flag
$forceInstall = in_array('--force', $argv ?? []);

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
        'options' => [
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    ]);

    $pdo = $connection->pdo();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    echo "✓ Database connection established\n\n";

    // Check if database already has tables (safety check)
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor();

    if (count($tables) > 0 && !$forceInstall) {
        echo "⚠ Warning: Database already contains " . count($tables) . " table(s).\n";
        echo "  This script is for fresh installations only.\n";
        echo "  Use upgrade.php to apply incremental updates to existing databases.\n\n";
        echo "  To force installation anyway, run: php install_db.php --force\n";
        echo "  WARNING: This may cause errors if tables already exist.\n\n";
        exit(1);
    }

    // Read the installation SQL file
    $installFile = __DIR__ . '/database/install/install.sql';

    if (!file_exists($installFile)) {
        throw new RuntimeException("Installation file not found: {$installFile}");
    }

    echo "→ Reading installation file...\n";
    $sql = file_get_contents($installFile);

    if ($sql === false) {
        throw new RuntimeException("Unable to read installation file");
    }

    echo "→ Processing SQL statements...\n\n";

    // Check if the file uses a custom DELIMITER (e.g., //)
    $delimiter = ';';
    if (preg_match('/^DELIMITER\s+(\S+)/m', $sql, $match)) {
        $delimiter = $match[1];
    }

    // Remove "DELIMITER //" lines (MySQL doesn't understand them via PDO)
    $cleanSql = preg_replace('/^DELIMITER\s+\S+\s*$/m', '', $sql);

    $statements = [];

    if ($delimiter === ';') {
        // Standard Mode: Use regex for semicolons (handles quotes correctly)
        $pcreResult = preg_match_all('/((?:[^;\'"]+|(?:\'(?:\\\\.|[^\'])*\')|(?:"(?:\\\\.|[^"])*"))+)/', $cleanSql, $matches);
        if ($pcreResult === false) {
            throw new RuntimeException("Unable to parse installation file (Regex Error)");
        }
        $statements = $matches[0];
    } else {
        // Custom Delimiter Mode: Simple explode
        $statements = explode($delimiter, $cleanSql);
    }

    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    foreach ($statements as $statement) {
        // Strip comments before checking for empty statements
        $statement = preg_replace('/^--.*$/m', '', $statement);
        $statement = trim($statement);

        if (empty($statement)) {
            continue;
        }

        try {
            $stmt = $pdo->prepare($statement);
            $stmt->execute();
            $stmt->closeCursor();
            $successCount++;
        } catch (PDOException $e) {
            $errorCount++;
            // Extract first line of statement for error reporting
            $firstLine = strtok($statement, "\n");
            if (strlen($firstLine) > 80) {
                $firstLine = substr($firstLine, 0, 77) . '...';
            }
            $errors[] = [
                'statement' => $firstLine,
                'error' => $e->getMessage()
            ];
        }
    }

    // Create migrations tracking table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Mark all current migrations as executed
    $migrationFiles = glob(__DIR__ . '/database/migrations/*.sql');
    sort($migrationFiles);

    $insertStmt = $pdo->prepare("INSERT IGNORE INTO migrations (migration) VALUES (?)");
    foreach ($migrationFiles as $file) {
        $migrationName = basename($file);
        if (strpos($migrationName, 'README') !== false) {
            continue;
        }
        $insertStmt->execute([$migrationName]);
    }
    $insertStmt->closeCursor();

    echo "================================\n";
    echo "Installation Summary\n";
    echo "================================\n";
    echo "✓ Statements executed: {$successCount}\n";

    if ($errorCount > 0) {
        echo "✗ Statements with errors: {$errorCount}\n\n";
        echo "Errors encountered:\n";
        foreach (array_slice($errors, 0, 10) as $err) {
            echo "  - {$err['statement']}\n";
            echo "    Error: {$err['error']}\n\n";
        }
        if (count($errors) > 10) {
            echo "  ... and " . (count($errors) - 10) . " more errors\n\n";
        }
    }

    echo "\n✓ Database installation completed!\n";
    echo "  Migration tracking has been initialized.\n";
    echo "  Use upgrade.php for future database updates.\n";
    echo "================================\n\n";

} catch (Exception $e) {
    echo "\n✗ Installation failed!\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
