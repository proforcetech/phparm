<?php

/**
 * PHPArm Interactive Installation Script
 *
 * This script handles initial configuration:
 * - Creates .env from .env.example
 * - Prompts for database credentials
 * - Generates JWT secret
 * - Creates admin user
 * - Optionally installs demo data
 *
 * Usage: php install.php
 */

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Color codes for terminal output
define('COLOR_RESET', "\033[0m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_RED', "\033[31m");
define('COLOR_CYAN', "\033[36m");
define('COLOR_BOLD', "\033[1m");

/**
 * Print colored output
 */
function output($message, $color = null) {
    if ($color) {
        echo $color . $message . COLOR_RESET;
    } else {
        echo $message;
    }
}

/**
 * Print a header section
 */
function header_section($title) {
    echo "\n" . COLOR_BOLD . COLOR_CYAN;
    echo "========================================\n";
    echo " {$title}\n";
    echo "========================================\n";
    echo COLOR_RESET;
}

/**
 * Prompt user for input
 */
function prompt($question, $default = null, $hidden = false) {
    $defaultDisplay = $default !== null ? " [{$default}]" : "";
    output("{$question}{$defaultDisplay}: ", COLOR_YELLOW);

    if ($hidden) {
        // Hide input for passwords
        system('stty -echo');
        $input = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $input = trim(fgets(STDIN));
    }

    return $input !== '' ? $input : $default;
}

/**
 * Ask yes/no question
 */
function confirm($question, $default = true) {
    $options = $default ? "[Y/n]" : "[y/N]";
    output("{$question} {$options}: ", COLOR_YELLOW);
    $input = strtolower(trim(fgets(STDIN)));

    if ($input === '') {
        return $default;
    }

    return in_array($input, ['y', 'yes']);
}

/**
 * Generate a secure random string
 */
function generate_secret($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a secure random password
 */
function generate_password($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

/**
 * Test database connection
 */
function test_db_connection($host, $port, $database, $username, $password) {
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return ['success' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Parse .env file into array
 */
function parse_env_file($filepath) {
    $env = [];
    if (!file_exists($filepath)) {
        return $env;
    }

    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            $env[] = ['type' => 'comment', 'content' => $line];
            continue;
        }

        // Skip empty lines
        if (trim($line) === '') {
            $env[] = ['type' => 'empty', 'content' => ''];
            continue;
        }

        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $env[] = [
                'type' => 'var',
                'key' => trim($key),
                'value' => trim($value),
                'original' => $line
            ];
        } else {
            $env[] = ['type' => 'other', 'content' => $line];
        }
    }

    return $env;
}

/**
 * Update a value in the parsed env array
 */
function update_env_value(&$env, $key, $newValue) {
    foreach ($env as &$item) {
        if ($item['type'] === 'var' && $item['key'] === $key) {
            // Preserve quotes if value contains spaces or special chars
            if (preg_match('/[\s#]/', $newValue) || $newValue === '') {
                $newValue = '"' . $newValue . '"';
            }
            $item['value'] = $newValue;
            return true;
        }
    }
    return false;
}

/**
 * Write parsed env array back to file
 */
function write_env_file($filepath, $env) {
    $content = '';
    foreach ($env as $item) {
        switch ($item['type']) {
            case 'comment':
            case 'other':
                $content .= $item['content'] . "\n";
                break;
            case 'empty':
                $content .= "\n";
                break;
            case 'var':
                $content .= $item['key'] . '=' . $item['value'] . "\n";
                break;
        }
    }

    return file_put_contents($filepath, $content) !== false;
}

/**
 * Create admin user in database
 */
function create_admin_user($pdo, $name, $email, $password) {
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        // Update existing user
        $stmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', active = 1, email_verified = 1, updated_at = NOW() WHERE email = ?");
        $stmt->execute([$name, $hashedPassword, $email]);
        return ['action' => 'updated', 'id' => null];
    } else {
        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, active, email_verified, created_at, updated_at) VALUES (?, ?, ?, 'admin', 1, 1, NOW(), NOW())");
        $stmt->execute([$name, $hashedPassword]);
        return ['action' => 'created', 'id' => $pdo->lastInsertId()];
    }
}

/**
 * Run SQL file against database
 */
function run_sql_file($pdo, $filepath) {
    if (!file_exists($filepath)) {
        return ['success' => false, 'error' => "File not found: {$filepath}"];
    }

    $sql = file_get_contents($filepath);

    // Handle custom delimiters
    $delimiter = ';';
    if (preg_match('/^DELIMITER\s+(\S+)/m', $sql, $match)) {
        $delimiter = $match[1];
    }

    // Remove DELIMITER lines
    $cleanSql = preg_replace('/^DELIMITER\s+\S+\s*$/m', '', $sql);

    // Split into statements
    if ($delimiter === ';') {
        preg_match_all('/((?:[^;\'"]+|(?:\'(?:\\\\.|[^\'])*\')|(?:"(?:\\\\.|[^"])*"))+)/', $cleanSql, $matches);
        $statements = $matches[0];
    } else {
        $statements = explode($delimiter, $cleanSql);
    }

    $successCount = 0;
    $errorCount = 0;

    foreach ($statements as $statement) {
        $statement = preg_replace('/^--.*$/m', '', $statement);
        $statement = trim($statement);

        if (empty($statement)) {
            continue;
        }

        try {
            $pdo->exec($statement);
            $successCount++;
        } catch (PDOException $e) {
            $errorCount++;
        }
    }

    return ['success' => true, 'executed' => $successCount, 'errors' => $errorCount];
}

// ============================================
// MAIN INSTALLATION PROCESS
// ============================================

echo "\n";
echo COLOR_BOLD . COLOR_GREEN;
echo "╔════════════════════════════════════════════╗\n";
echo "║                                            ║\n";
echo "║     PHPArm Installation Wizard             ║\n";
echo "║                                            ║\n";
echo "╚════════════════════════════════════════════╝\n";
echo COLOR_RESET;

$baseDir = __DIR__;

// Step 1: Check prerequisites
header_section("Checking Prerequisites");

$checks = [
    'PHP Version (>= 8.1)' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO Extension' => extension_loaded('pdo'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'JSON Extension' => extension_loaded('json'),
    'OpenSSL Extension' => extension_loaded('openssl'),
    'Mbstring Extension' => extension_loaded('mbstring'),
];

$allPassed = true;
foreach ($checks as $check => $passed) {
    if ($passed) {
        output("  ✓ {$check}\n", COLOR_GREEN);
    } else {
        output("  ✗ {$check}\n", COLOR_RED);
        $allPassed = false;
    }
}

if (!$allPassed) {
    output("\n✗ Some prerequisites are not met. Please install missing extensions.\n", COLOR_RED);
    exit(1);
}

// Step 2: Create .env file
header_section("Environment Configuration");

$envExample = $baseDir . '/.env.example';
$envFile = $baseDir . '/.env';

if (!file_exists($envExample)) {
    output("✗ .env.example not found!\n", COLOR_RED);
    exit(1);
}

if (file_exists($envFile)) {
    if (!confirm("  .env file already exists. Overwrite?", false)) {
        output("  Using existing .env file.\n", COLOR_YELLOW);
        $env = parse_env_file($envFile);
    } else {
        copy($envExample, $envFile);
        output("  ✓ Created new .env from .env.example\n", COLOR_GREEN);
        $env = parse_env_file($envFile);
    }
} else {
    copy($envExample, $envFile);
    output("  ✓ Created .env from .env.example\n", COLOR_GREEN);
    $env = parse_env_file($envFile);
}

// Step 3: Application Settings
header_section("Application Settings");

$appName = prompt("  Application Name", "PHPArm");
$appUrl = prompt("  Application URL", "http://localhost");
$appEnv = prompt("  Environment (local/production)", "production");
$appDebug = $appEnv === 'local' ? 'true' : 'false';

update_env_value($env, 'APP_NAME', $appName);
update_env_value($env, 'APP_URL', $appUrl);
update_env_value($env, 'APP_ENV', $appEnv);
update_env_value($env, 'APP_DEBUG', $appDebug);
update_env_value($env, 'VITE_APP_NAME', $appName);

// Step 4: Database Configuration
header_section("Database Configuration");

$dbConnected = false;
$pdo = null;

while (!$dbConnected) {
    $dbHost = prompt("  Database Host", "localhost");
    $dbPort = prompt("  Database Port", "3306");
    $dbName = prompt("  Database Name", "phparm");
    $dbUser = prompt("  Database Username", "phparm_user");
    $dbPass = prompt("  Database Password", null, true);

    output("  Testing connection...", COLOR_YELLOW);

    $result = test_db_connection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

    if ($result['success']) {
        output(" ✓ Connected!\n", COLOR_GREEN);
        $pdo = $result['pdo'];
        $dbConnected = true;
    } else {
        output(" ✗ Failed\n", COLOR_RED);
        output("  Error: {$result['error']}\n", COLOR_RED);

        if (!confirm("  Try again?", true)) {
            output("\n✗ Database connection is required. Exiting.\n", COLOR_RED);
            exit(1);
        }
    }
}

update_env_value($env, 'DB_HOST', $dbHost);
update_env_value($env, 'DB_PORT', $dbPort);
update_env_value($env, 'DB_DATABASE', $dbName);
update_env_value($env, 'DB_USERNAME', $dbUser);
update_env_value($env, 'DB_PASSWORD', $dbPass);

// Step 5: Generate JWT Secret
header_section("Security Configuration");

$jwtSecret = generate_secret(64);
output("  ✓ Generated JWT secret ({$jwtSecret})\n", COLOR_GREEN);

update_env_value($env, 'JWT_SECRET', $jwtSecret);

// Step 6: Email Configuration (Optional)
header_section("Email Configuration (Optional)");

if (confirm("  Configure email settings now?", false)) {
    $mailHost = prompt("  SMTP Host", "smtp.mailtrap.io");
    $mailPort = prompt("  SMTP Port", "587");
    $mailUser = prompt("  SMTP Username");
    $mailPass = prompt("  SMTP Password", null, true);
    $mailFrom = prompt("  From Email Address", "noreply@" . parse_url($appUrl, PHP_URL_HOST));

    update_env_value($env, 'MAIL_HOST', $mailHost);
    update_env_value($env, 'MAIL_PORT', $mailPort);
    update_env_value($env, 'MAIL_USERNAME', $mailUser);
    update_env_value($env, 'MAIL_PASSWORD', $mailPass);
    update_env_value($env, 'MAIL_FROM_ADDRESS', $mailFrom);
} else {
    output("  Skipping email configuration. You can configure later in .env\n", COLOR_YELLOW);
}

// Step 7: Business Settings
header_section("Business Settings");

$businessName = prompt("  Business Name", "Your Auto Repair Shop");
$taxRate = prompt("  Default Tax Rate (e.g., 0.0875 for 8.75%)", "0.0875");

update_env_value($env, 'BUSINESS_NAME', $businessName);
update_env_value($env, 'BUSINESS_TAX_RATE', $taxRate);

// Step 8: Save .env file
header_section("Saving Configuration");

if (write_env_file($envFile, $env)) {
    output("  ✓ Configuration saved to .env\n", COLOR_GREEN);

    // Set file permissions (readable by owner only for security)
    chmod($envFile, 0600);
    output("  ✓ Set file permissions (0600)\n", COLOR_GREEN);
} else {
    output("  ✗ Failed to save .env file\n", COLOR_RED);
    exit(1);
}

// Step 9: Install Database Schema
header_section("Database Installation");

$installFile = $baseDir . '/database/install/install.sql';
$migrationsDir = $baseDir . '/database/migrations';

// Check if tables already exist
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($tables) > 0) {
    output("  Database already contains " . count($tables) . " table(s).\n", COLOR_YELLOW);

    if (confirm("  Run database installation anyway? (may cause errors)", false)) {
        $runInstall = true;
    } else {
        $runInstall = false;
        output("  Skipping database installation.\n", COLOR_YELLOW);
    }
} else {
    $runInstall = true;
}

if ($runInstall) {
    if (file_exists($installFile)) {
        output("  Running install.sql...\n", COLOR_YELLOW);
        $result = run_sql_file($pdo, $installFile);

        if ($result['success']) {
            output("  ✓ Executed {$result['executed']} statements", COLOR_GREEN);
            if ($result['errors'] > 0) {
                output(" ({$result['errors']} errors)", COLOR_YELLOW);
            }
            output("\n");
        }

        // Create migrations tracking table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Mark all migrations as executed
        $migrationFiles = glob($migrationsDir . '/*.sql');
        sort($migrationFiles);

        $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (migration) VALUES (?)");
        foreach ($migrationFiles as $file) {
            $name = basename($file);
            if (strpos($name, 'README') === false) {
                $stmt->execute([$name]);
            }
        }

        output("  ✓ Migration tracking initialized\n", COLOR_GREEN);
    } else {
        output("  ✗ install.sql not found. Run migrations manually with upgrade.php\n", COLOR_YELLOW);
    }
}

// Step 10: Create Admin User
header_section("Create Admin User");

output("  Please provide details for the admin account:\n\n");

$adminName = prompt("  Full Name", "Administrator");
$adminEmail = prompt("  Email Address");

while (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    output("  Invalid email format. Please try again.\n", COLOR_RED);
    $adminEmail = prompt("  Email Address");
}

$generatePassword = confirm("  Generate random password?", true);

if ($generatePassword) {
    $adminPassword = generate_password(16);
    output("  Generated password: ", COLOR_YELLOW);
    output("{$adminPassword}\n", COLOR_BOLD);
    output("  (Save this password - it won't be shown again)\n\n", COLOR_YELLOW);
} else {
    $adminPassword = prompt("  Password", null, true);

    // Confirm password
    $confirmPassword = prompt("  Confirm Password", null, true);

    while ($adminPassword !== $confirmPassword) {
        output("  Passwords do not match. Please try again.\n", COLOR_RED);
        $adminPassword = prompt("  Password", null, true);
        $confirmPassword = prompt("  Confirm Password", null, true);
    }
}

$result = create_admin_user($pdo, $adminName, $adminEmail, $adminPassword);

if ($result['action'] === 'created') {
    output("  ✓ Admin user created successfully\n", COLOR_GREEN);
} else {
    output("  ✓ Admin user updated successfully\n", COLOR_GREEN);
}

// Step 11: Install Demo Data
header_section("Demo Data");

$seedFile = $baseDir . '/database/seed_data.sql';

if (file_exists($seedFile)) {
    if (confirm("  Install demo/sample data?", false)) {
        output("  Installing seed data...\n", COLOR_YELLOW);
        $result = run_sql_file($pdo, $seedFile);

        if ($result['success']) {
            output("  ✓ Demo data installed ({$result['executed']} statements)\n", COLOR_GREEN);
        }
    } else {
        output("  Skipping demo data installation.\n", COLOR_YELLOW);
    }
} else {
    output("  No seed_data.sql found. Skipping.\n", COLOR_YELLOW);
}

// Step 12: Final Summary
header_section("Installation Complete!");

output("\n  Your PHPArm installation is ready!\n\n", COLOR_GREEN);

output("  ┌─────────────────────────────────────────┐\n", COLOR_CYAN);
output("  │  Configuration Summary                  │\n", COLOR_CYAN);
output("  ├─────────────────────────────────────────┤\n", COLOR_CYAN);
output("  │  URL: {$appUrl}\n", COLOR_CYAN);
output("  │  Database: {$dbName}@{$dbHost}\n", COLOR_CYAN);
output("  │  Admin Email: {$adminEmail}\n", COLOR_CYAN);
output("  └─────────────────────────────────────────┘\n", COLOR_CYAN);

output("\n  Next Steps:\n", COLOR_BOLD);
output("  1. Run: composer install\n");
output("  2. Run: npm install && npm run build\n");
output("  3. Configure your web server to point to the /public directory\n");
output("  4. Access your application at: {$appUrl}\n");

output("\n  For future database updates, run: php upgrade.php\n\n", COLOR_YELLOW);
