<?php

/**
 * PHPArm Database Upgrade Script
 *
 * This script applies incremental database migrations to an existing installation.
 * For new installations, use install_db.php instead.
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
echo "PHPArm Database Upgrade\n";
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
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration file: {$migrationName}");
            }

            $statements = splitSqlStatements($sql);

            foreach ($statements as $statement) {
                $stmt = $pdo->prepare($statement);
                $stmt->execute();
                $stmt->closeCursor();
            }
            
            // Record migration
            $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
            $stmt->execute([$migrationName]);
            $stmt->closeCursor();
            
            echo " ✓\n";
            $runCount++;
            
        } catch (Exception $e) {
            echo " ✗\n";
            echo "  Error: " . $e->getMessage() . "\n\n";
            // Continue with next migration instead of stopping
            continue;
        }
    }
    
    echo "\n================================\n";
    if ($runCount > 0) {
        echo "✓ Upgrade completed!\n";
        echo "  Applied: {$runCount} upgrade(s)\n";
    } else {
        echo "✓ Database is up to date!\n";
    }
    echo "================================\n\n";

} catch (Exception $e) {
    echo "\n✗ Upgrade failed!\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

/**
 * Split a MySQL script into individual statements.
 *
 * Strips line and block comments first, then walks the SQL char-by-char
 * tracking string and identifier quoting so that semicolons inside strings,
 * comments, or backtick-quoted identifiers don't terminate a statement.
 * Handles SQL's '' / "" doubled-quote escapes as well as backslash escapes.
 * Honors `DELIMITER //` directives for stored-procedure-style files.
 */
function splitSqlStatements(string $sql): array
{
    // Strip /* ... */ block comments (non-greedy, dotall).
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql);
    // Strip -- line comments to end of line.
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    // Strip # line comments to end of line.
    $sql = preg_replace('/#[^\n]*/', '', $sql);

    $statements = [];
    $current = '';
    $delimiter = ';';
    $delimLen = 1;
    $len = strlen($sql);
    $i = 0;
    $state = 'NORMAL'; // NORMAL | SQ | DQ | BT

    $pushStatement = function () use (&$current, &$statements) {
        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
        $current = '';
    };

    while ($i < $len) {
        $c = $sql[$i];

        if ($state === 'NORMAL') {
            // DELIMITER directive — only meaningful at the start of a logical line.
            if (($i === 0 || $sql[$i - 1] === "\n") && stripos(substr($sql, $i, 10), 'DELIMITER ') === 0) {
                $eol = strpos($sql, "\n", $i);
                if ($eol === false) {
                    $eol = $len;
                }
                $newDelim = trim(substr($sql, $i + 10, $eol - $i - 10));
                if ($newDelim !== '') {
                    $delimiter = $newDelim;
                    $delimLen = strlen($delimiter);
                }
                $i = $eol + 1;
                continue;
            }

            if ($c === "'") {
                $state = 'SQ';
                $current .= $c;
                $i++;
                continue;
            }
            if ($c === '"') {
                $state = 'DQ';
                $current .= $c;
                $i++;
                continue;
            }
            if ($c === '`') {
                $state = 'BT';
                $current .= $c;
                $i++;
                continue;
            }
            if (substr($sql, $i, $delimLen) === $delimiter) {
                $pushStatement();
                $i += $delimLen;
                continue;
            }
            $current .= $c;
            $i++;
            continue;
        }

        if ($state === 'SQ') {
            $current .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $current .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $current .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $state = 'NORMAL';
            }
            $i++;
            continue;
        }

        if ($state === 'DQ') {
            $current .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $current .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === '"') {
                if ($i + 1 < $len && $sql[$i + 1] === '"') {
                    $current .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $state = 'NORMAL';
            }
            $i++;
            continue;
        }

        if ($state === 'BT') {
            $current .= $c;
            if ($c === '`') {
                if ($i + 1 < $len && $sql[$i + 1] === '`') {
                    $current .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $state = 'NORMAL';
            }
            $i++;
            continue;
        }
    }

    $pushStatement();

    return $statements;
}
