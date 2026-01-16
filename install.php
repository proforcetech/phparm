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
function create_admin_user($pdo, $name, $email, $password, $twoFactorSecret = null, $twoFactorEnabled = false, $twoFactorPending = false) {
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);

    $existing = $stmt->fetch();

    if ($existing) {
        // Update existing user
        $stmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', active = 1, email_verified = 1, two_factor_secret = ?, two_factor_enabled = ?, two_factor_setup_pending = ?, updated_at = NOW() WHERE email = ?");
        $stmt->execute([$name, $hashedPassword, $twoFactorSecret, $twoFactorEnabled ? 1 : 0, $twoFactorPending ? 1 : 0, $email]);
        return ['action' => 'updated', 'id' => $existing['id'] ?? null];
    } else {
        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, active, email_verified, two_factor_secret, two_factor_enabled, two_factor_setup_pending, created_at, updated_at) VALUES (?, ?, ?, 'admin', 1, 1, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$name, $email, $hashedPassword, $twoFactorSecret, $twoFactorEnabled ? 1 : 0, $twoFactorPending ? 1 : 0]);
        return ['action' => 'created', 'id' => $pdo->lastInsertId()];
    }
}

/**
 * Generate TOTP secret (Base32 encoded)
 */
function generate_totp_secret($length = 16) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $secret;
}

/**
 * Generate TOTP code for verification
 */
function generate_totp_code($secret, $timestamp = null) {
    $period = 30;
    $digits = 6;
    $timestamp = $timestamp ?? time();
    $counter = floor($timestamp / $period);

    // Base32 decode
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binaryString = '';
    for ($i = 0; $i < strlen($secret); $i++) {
        $current = strpos($alphabet, $secret[$i]);
        if ($current === false) {
            return null;
        }
        $binaryString .= str_pad(decbin($current), 5, '0', STR_PAD_LEFT);
    }

    $eightBits = str_split($binaryString, 8);
    $key = '';
    foreach ($eightBits as $bits) {
        if (strlen($bits) === 8) {
            $key .= chr(bindec($bits));
        }
    }

    $binaryCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    $otp = $truncated % (10 ** $digits);

    return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify TOTP code
 */
function verify_totp_code($secret, $code, $window = 1) {
    $timestamp = time();
    $normalizedCode = preg_replace('/\s+/', '', $code);

    for ($i = -$window; $i <= $window; $i++) {
        $expected = generate_totp_code($secret, $timestamp + ($i * 30));
        if ($expected !== null && hash_equals($expected, $normalizedCode)) {
            return true;
        }
    }

    return false;
}

/**
 * Get otpauth URI for authenticator apps
 */
function get_totp_uri($secret, $email, $issuer = 'PHPArm') {
    $issuer = rawurlencode($issuer);
    $email = rawurlencode($email);
    return "otpauth://totp/{$issuer}:{$email}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
}

/**
 * Get list of existing application tables
 */
function get_existing_tables($pdo, $prefix = '') {
    $stmt = $pdo->query("SHOW TABLES");
    $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Known application tables (from install.sql)
    $appTables = [
    'appointment_slots', 'appointments', 'approval_audit_log', 'auction_lots', 'audit_logs', 
    'availability_settings', 'barcode_scan_log', 'bundle_items', 'bundle_items,', 'bundles',
    'cms_cache', 'cms_categories', 'cms_categories,', 'cms_components', 'cms_media', 'cms_menu_items', 
    'cms_menus', 'cms_pages', 'cms_settings', 'cms_templates', 'cms_templates,', 'core_return_history', 
    'core_returns', 'credit_accounts', 'credit_payment_reminders', 'credit_payments', 'credit_transactions', 
    'custom_roles', 'customer_credit,', 'customer_vehicles', 'customers', 'dispatch_audit_log', 'dispatch_offers',
    'dispatch_requirements', 'driver_certifications', 'driver_idle_alerts', 'driver_job_offers', 'driver_locations',
    'driver_performance_metrics', 'driver_profiles', 'driver_push_tokens', 'driver_shifts', 'driver_tokens,', 'email_verifications',
    'equipment_job_requirements', 'estimate_items', 'estimate_job_feedback', 'estimate_job_rejections', 'estimate_jobs', 'estimate_public_comments',
    'estimate_public_links', 'estimate_request_media', 'estimate_requests', 'estimate_signatures', 'estimates', 'financial_categories', 'financial_entries',
    'financial_vendors,', 'geofence_events', 'geofences', 'impound_cases', 'inspection_estimate_conversions', 'inspection_items', 'inspection_recommendations',
    'inspection_report_items', 'inspection_report_media', 'inspection_report_signatures', 'inspection_reports', 'inspection_sections', 'inspection_templates',
    'inspection_templates,', 'inspections', 'inventory', 'inventory_adjustments,', 'inventory_categories,', 'inventory_items', 'inventory_locations', 'inventory_lookups',
    'inventory_pull_requests', 'inventory_reorder_point_history', 'inventory_stock_orders', 'inventory_transactions', 'inventory_vehicle_compatibility',
    'inventory_vendors', 'invoice_items', 'invoice_payer_allocations', 'invoice_public_payment_tokens', 'invoices', 'job_checkpoint_media', 'job_damage_media',
    'job_damage_reports', 'job_density_snapshots', 'job_signatures', 'job_tracking_links', 'job_vehicle_intakes', 'labor_tasks', 'lien_notices', 'masked_sms_messages',
    'masked_sms_sessions', 'message_attachments', 'message_messages', 'message_notification_threads', 'message_participants', 'message_reads', 'message_threads',
    'messages', 'migrations,', 'module_access_log', 'module_settings', 'not_found_logs', 'notification_logs', 'notification_templates', 'notifications', 
    'offer_rejection_reasons', 'partner_accounts', 'partner_dispatch_requests', 'partner_request_attachments', 'parts_cart_items', 'parts_carts', 'password_resets',
    'payment_sessions', 'payment_transactions', 'payments', 'pull_request_items', 'pull_requests', 'qc_check_items', 'qc_checks', 'qc_template_items', 'qc_templates',
    'redirects', 'refunds', 'reminder_campaigns', 'reminder_logs', 'reminder_preferences', 'roadside_calls', 'role_permissions', 'roles', 'service_types', 'settings',
    'stock_order_items', 'stock_orders', 'storage_fees', 'storage_rates', 'storage_rates,', 'time_adjustments', 'time_entries', 'towing_price_matrix', 'towing_rates',
    'towing_service_classes', 'towing_service_types', 'truck_checklist_entries', 'truck_checklist_entry_items', 'truck_checklist_template_items',
    'truck_checklist_templates', 'truck_checklists', 'truck_equipment', 'user_group_members', 'user_group_members,', 'user_groups', 'user_sessions', 'user_sessions,',
    'users', 'vehicle_master', 'warranty_claim_messages', 'warranty_claims', 'waterfall_dispatch_sequences', 'workorder_items', 'workorder_jobs', 'workorder_notes,',
    'workorder_notification_rules', 'workorder_signatures', 'workorder_status_history', 'workorders'
    ];

    $existing = [];
    foreach ($allTables as $table) {
        $tableName = $prefix ? preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table) : $table;
        if (in_array($tableName, $appTables) || in_array($table, $appTables)) {
            $existing[] = $table;
        }
    }

    return $existing;
}

/**
 * Dump tables to SQL file
 */
function dump_tables($pdo, $tables, $outputFile) {
    $dump = "-- PHPArm Database Backup\n";
    $dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $dump .= "-- Tables: " . count($tables) . "\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        // Get CREATE TABLE statement
        $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $createTable = $row['Create Table'] ?? null;

        if ($createTable) {
            $dump .= "-- Table: {$table}\n";
            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $dump .= $createTable . ";\n\n";

            // Get table data
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $columnList = implode('`, `', $columns);

                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($pdo) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return $pdo->quote($value);
                    }, array_values($row));
                    $valueList = implode(', ', $values);
                    $dump .= "INSERT INTO `{$table}` (`{$columnList}`) VALUES ({$valueList});\n";
                }
                $dump .= "\n";
            }
        }
    }

    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    return file_put_contents($outputFile, $dump) !== false;
}

/**
 * Drop tables from database
 */
function drop_tables($pdo, $tables) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $dropped = 0;
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            $dropped++;
        } catch (PDOException $e) {
            // Continue even if one fails
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    return $dropped;
}

/**
 * Show menu and get user choice
 */
function menu($title, $options) {
    output("\n  {$title}\n", COLOR_BOLD);
    output("  " . str_repeat("-", strlen($title)) . "\n");

    foreach ($options as $key => $label) {
        output("  [{$key}] {$label}\n", COLOR_YELLOW);
    }

    output("\n");
    $choice = prompt("  Enter your choice");

    return $choice;
}

/**
 * Get the installation log file path
 */
function get_install_log_path() {
    $logDir = __DIR__ . '/storage/logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    return $logDir . '/install_' . date('Y-m-d') . '.log';
}

/**
 * Log a message to the installation log file
 */
function log_install_error($message, $context = []) {
    $logFile = get_install_log_path();
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}";
    if (!empty($context)) {
        $logEntry .= " | Context: " . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    $logEntry .= "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Run SQL file against database
 */
function run_sql_file($pdo, $filepath, $stopOnError = true) {
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
    $errors = [];

    foreach ($statements as $statementIndex => $statement) {
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
            $errorInfo = [
                'statement_index' => $statementIndex + 1,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'statement_preview' => substr($statement, 0, 200) . (strlen($statement) > 200 ? '...' : ''),
            ];
            $errors[] = $errorInfo;

            // Log the error
            log_install_error("SQL execution error in {$filepath}", $errorInfo);

            if ($stopOnError) {
                return [
                    'success' => false,
                    'executed' => $successCount,
                    'errors' => $errorCount,
                    'error_details' => $errors,
                    'log_file' => get_install_log_path(),
                ];
            }
        }
    }

    return [
        'success' => $errorCount === 0,
        'executed' => $successCount,
        'errors' => $errorCount,
        'error_details' => $errors,
        'log_file' => $errorCount > 0 ? get_install_log_path() : null,
    ];
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
$backupDir = $baseDir . '/storage/backups';
$tablePrefix = '';

// Check if tables already exist
$existingTables = get_existing_tables($pdo);

if (count($existingTables) > 0) {
    output("\n", COLOR_YELLOW);
    output("  ⚠ WARNING: Found " . count($existingTables) . " existing PHPArm table(s):\n", COLOR_RED);
    output("  " . implode(", ", array_slice($existingTables, 0, 5)), COLOR_YELLOW);
    if (count($existingTables) > 5) {
        output(" ... and " . (count($existingTables) - 5) . " more", COLOR_YELLOW);
    }
    output("\n\n");

    $choice = menu("How would you like to handle existing tables?", [
        '1' => 'Add a prefix to new tables (keeps existing tables)',
        '2' => 'Backup existing tables to SQL file, then drop them',
        '3' => 'Drop existing tables WITHOUT backup (destructive!)',
        '4' => 'Skip database installation (keep existing tables)',
    ]);

    switch ($choice) {
        case '1':
            // Add prefix option
            $tablePrefix = prompt("  Enter table prefix (e.g., 'new_')", "phparm_");
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tablePrefix)) {
                output("  Invalid prefix. Using 'phparm_'\n", COLOR_YELLOW);
                $tablePrefix = 'phparm_';
            }
            output("  ✓ Will use prefix: {$tablePrefix}\n", COLOR_GREEN);
            $runInstall = true;
            break;

        case '2':
            // Backup and drop
            if (!file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $backupFile = $backupDir . '/backup_' . date('Y-m-d_His') . '.sql';
            output("  Creating backup...\n", COLOR_YELLOW);

            if (dump_tables($pdo, $existingTables, $backupFile)) {
                output("  ✓ Backup saved to: {$backupFile}\n", COLOR_GREEN);

                if (confirm("  Proceed to drop " . count($existingTables) . " tables?", true)) {
                    $dropped = drop_tables($pdo, $existingTables);
                    output("  ✓ Dropped {$dropped} table(s)\n", COLOR_GREEN);
                    $runInstall = true;
                } else {
                    output("  Aborted. Tables not dropped.\n", COLOR_YELLOW);
                    $runInstall = false;
                }
            } else {
                output("  ✗ Failed to create backup. Aborting.\n", COLOR_RED);
                $runInstall = false;
            }
            break;

        case '3':
            // Drop without backup - requires multiple confirmations
            output("\n", COLOR_RED);
            output("  ╔════════════════════════════════════════════════════════════╗\n", COLOR_RED);
            output("  ║  ⚠  DANGER: This will permanently delete all data!        ║\n", COLOR_RED);
            output("  ║  This action CANNOT be undone!                             ║\n", COLOR_RED);
            output("  ╚════════════════════════════════════════════════════════════╝\n", COLOR_RED);
            output("\n");

            if (!confirm("  Are you ABSOLUTELY sure you want to delete all tables?", false)) {
                output("  Aborted.\n", COLOR_YELLOW);
                $runInstall = false;
            } else {
                output("\n  Type 'DELETE ALL DATA' to confirm: ", COLOR_RED);
                $confirmation = trim(fgets(STDIN));

                if ($confirmation === 'DELETE ALL DATA') {
                    if (!confirm("  FINAL WARNING: Drop " . count($existingTables) . " tables now?", false)) {
                        output("  Aborted.\n", COLOR_YELLOW);
                        $runInstall = false;
                    } else {
                        $dropped = drop_tables($pdo, $existingTables);
                        output("  ✓ Dropped {$dropped} table(s)\n", COLOR_GREEN);
                        $runInstall = true;
                    }
                } else {
                    output("  Confirmation failed. Aborted.\n", COLOR_YELLOW);
                    $runInstall = false;
                }
            }
            break;

        case '4':
        default:
            output("  Skipping database installation.\n", COLOR_YELLOW);
            $runInstall = false;
            break;
    }
} else {
    $runInstall = true;
}

if ($runInstall) {
    if (file_exists($installFile)) {
        output("  Running install.sql...\n", COLOR_YELLOW);

        // If using a prefix, we need to modify the SQL
        if ($tablePrefix !== '') {
            $sql = file_get_contents($installFile);
            // Add prefix to table names in CREATE TABLE, INSERT, ALTER, etc.
            $sql = preg_replace('/CREATE TABLE (IF NOT EXISTS )?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                'CREATE TABLE $1`' . $tablePrefix . '$2`', $sql);
            $sql = preg_replace('/INSERT INTO `?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                'INSERT INTO `' . $tablePrefix . '$1`', $sql);
            $sql = preg_replace('/ALTER TABLE `?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                'ALTER TABLE `' . $tablePrefix . '$1`', $sql);
            $sql = preg_replace('/REFERENCES `?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\(/i',
                'REFERENCES `' . $tablePrefix . '$1` (', $sql);
            $sql = preg_replace('/DROP TABLE (IF EXISTS )?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                'DROP TABLE $1`' . $tablePrefix . '$2`', $sql);

            // Write modified SQL to temp file
            $tempFile = $baseDir . '/storage/temp/install_prefixed.sql';
            if (!file_exists(dirname($tempFile))) {
                mkdir(dirname($tempFile), 0755, true);
            }
            file_put_contents($tempFile, $sql);
            $result = run_sql_file($pdo, $tempFile, true);
            unlink($tempFile);

            if (!$result['success']) {
                output("  ✗ Database installation failed!\n", COLOR_RED);
                if (!empty($result['error_details'])) {
                    foreach ($result['error_details'] as $err) {
                        output("    Error: {$err['error_message']}\n", COLOR_RED);
                    }
                }
                if (!empty($result['log_file'])) {
                    output("  See log file for details: {$result['log_file']}\n", COLOR_YELLOW);
                }
                exit(1);
            }
            output("  ✓ Tables created with prefix '{$tablePrefix}'\n", COLOR_GREEN);
            output("  Note: Update your .env to include DB_PREFIX={$tablePrefix}\n", COLOR_YELLOW);
        } else {
            $result = run_sql_file($pdo, $installFile, true);
        }

        if (!$result['success']) {
            output("  ✗ Database installation failed!\n", COLOR_RED);
            if (!empty($result['error_details'])) {
                foreach ($result['error_details'] as $err) {
                    output("    Error: {$err['error_message']}\n", COLOR_RED);
                }
            }
            if (!empty($result['log_file'])) {
                output("  See log file for details: {$result['log_file']}\n", COLOR_YELLOW);
            }
            exit(1);
        }

        output("  ✓ Executed {$result['executed']} statements", COLOR_GREEN);
        if ($result['errors'] > 0) {
            output(" ({$result['errors']} warnings)", COLOR_YELLOW);
        }
        output("\n");

        // Create migrations tracking table
        $migrationsTable = $tablePrefix . 'migrations';
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$migrationsTable}` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Mark all migrations as executed
        $migrationFiles = glob($migrationsDir . '/*.sql');
        sort($migrationFiles);

        $stmt = $pdo->prepare("INSERT IGNORE INTO `{$migrationsTable}` (migration) VALUES (?)");
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

// Step 10b: Two-Factor Authentication Setup
header_section("Two-Factor Authentication (2FA)");

output("  Admin accounts require Two-Factor Authentication for security.\n");
output("  You can set it up now or defer until first login.\n\n");

$twoFactorChoice = menu("How would you like to handle 2FA?", [
    '1' => 'Set up 2FA now (recommended)',
    '2' => 'Prompt for 2FA setup on first login',
    '3' => 'Disable 2FA requirement for this admin (not recommended)',
]);

$twoFactorSecret = null;
$twoFactorEnabled = false;
$twoFactorPending = false;

switch ($twoFactorChoice) {
    case '1':
        // Set up 2FA now
        $twoFactorSecret = generate_totp_secret(16);
        $otpauthUri = get_totp_uri($twoFactorSecret, $adminEmail, $appName);

        output("\n  ┌─────────────────────────────────────────────────────────────┐\n", COLOR_CYAN);
        output("  │  Scan this with your authenticator app (Google Authenticator,│\n", COLOR_CYAN);
        output("  │  Authy, 1Password, etc.) or enter the secret manually:       │\n", COLOR_CYAN);
        output("  └─────────────────────────────────────────────────────────────┘\n", COLOR_CYAN);
        output("\n");
        output("  Secret Key: ", COLOR_BOLD);
        output("{$twoFactorSecret}\n", COLOR_GREEN);
        output("\n  Or use this URI in your authenticator app:\n", COLOR_YELLOW);
        output("  {$otpauthUri}\n\n", COLOR_CYAN);

        // Verify the code
        $verified = false;
        $attempts = 0;
        $maxAttempts = 3;

        while (!$verified && $attempts < $maxAttempts) {
            $verifyCode = prompt("  Enter the 6-digit code from your authenticator app");
            $verifyCode = preg_replace('/\s+/', '', $verifyCode);

            if (verify_totp_code($twoFactorSecret, $verifyCode)) {
                $verified = true;
                $twoFactorEnabled = true;
                output("  ✓ 2FA verified and enabled!\n", COLOR_GREEN);
            } else {
                $attempts++;
                if ($attempts < $maxAttempts) {
                    output("  ✗ Invalid code. Please try again. (" . ($maxAttempts - $attempts) . " attempts remaining)\n", COLOR_RED);
                } else {
                    output("  ✗ Verification failed after {$maxAttempts} attempts.\n", COLOR_RED);
                    if (confirm("  Save 2FA secret anyway and try again at login?", true)) {
                        $twoFactorEnabled = false;
                        $twoFactorPending = true;
                        output("  2FA secret saved. You'll need to verify on first login.\n", COLOR_YELLOW);
                    } else {
                        $twoFactorSecret = null;
                        $twoFactorPending = true;
                        output("  2FA setup deferred to first login.\n", COLOR_YELLOW);
                    }
                }
            }
        }
        break;

    case '2':
        // Defer to first login
        $twoFactorPending = true;
        output("  ✓ 2FA setup will be required on first login.\n", COLOR_GREEN);
        break;

    case '3':
        // Disable requirement (not recommended)
        output("\n", COLOR_RED);
        output("  ⚠ WARNING: Disabling 2FA reduces account security!\n", COLOR_RED);
        output("  Admin accounts are high-value targets for attackers.\n", COLOR_RED);
        output("\n");

        if (confirm("  Are you sure you want to disable 2FA for this admin?", false)) {
            $twoFactorEnabled = false;
            $twoFactorPending = false;
            $twoFactorSecret = null;
            output("  2FA disabled for this admin account.\n", COLOR_YELLOW);
            output("  You can enable it later from Settings > Security.\n", COLOR_YELLOW);
        } else {
            $twoFactorPending = true;
            output("  ✓ 2FA setup will be required on first login.\n", COLOR_GREEN);
        }
        break;

    default:
        $twoFactorPending = true;
        output("  ✓ 2FA setup will be required on first login.\n", COLOR_GREEN);
        break;
}

// Determine the correct users table name (with prefix if used)
$usersTable = $tablePrefix . 'users';

// Check if users table exists before creating admin
$stmt = $pdo->query("SHOW TABLES LIKE '{$usersTable}'");
if ($stmt->rowCount() === 0) {
    output("  ✗ Users table not found. Skipping admin creation.\n", COLOR_RED);
    output("  Run database installation first.\n", COLOR_YELLOW);
} else {
    // Modify create_admin_user to use the correct table
    $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare("SELECT id FROM `{$usersTable}` WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE `{$usersTable}` SET name = ?, password = ?, role = 'admin', active = 1, email_verified = 1, two_factor_secret = ?, two_factor_enabled = ?, two_factor_setup_pending = ?, updated_at = NOW() WHERE email = ?");
        $stmt->execute([$adminName, $hashedPassword, $twoFactorSecret, $twoFactorEnabled ? 1 : 0, $twoFactorPending ? 1 : 0, $adminEmail]);
        output("  ✓ Admin user updated successfully\n", COLOR_GREEN);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `{$usersTable}` (name, email, password, role, active, email_verified, two_factor_secret, two_factor_enabled, two_factor_setup_pending, created_at, updated_at) VALUES (?, ?, ?, 'admin', 1, 1, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$adminName, $adminEmail, $hashedPassword, $twoFactorSecret, $twoFactorEnabled ? 1 : 0, $twoFactorPending ? 1 : 0]);
        output("  ✓ Admin user created successfully\n", COLOR_GREEN);
    }
}

// Step 11: Install Demo Data
header_section("Demo Data");

$seedFile = $baseDir . '/database/seed_data.sql';

if (file_exists($seedFile)) {
    if (confirm("  Install demo/sample data?", false)) {
        output("  Installing seed data...\n", COLOR_YELLOW);

        // If using a prefix, modify the seed SQL too
        if ($tablePrefix !== '') {
            $sql = file_get_contents($seedFile);
            $sql = preg_replace('/INSERT INTO `?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                'INSERT INTO `' . $tablePrefix . '$1`', $sql);
            $sql = preg_replace('/UPDATE `?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                'UPDATE `' . $tablePrefix . '$1`', $sql);

            $tempFile = $baseDir . '/storage/temp/seed_prefixed.sql';
            if (!file_exists(dirname($tempFile))) {
                mkdir(dirname($tempFile), 0755, true);
            }
            file_put_contents($tempFile, $sql);
            $result = run_sql_file($pdo, $tempFile, false);
            unlink($tempFile);
        } else {
            $result = run_sql_file($pdo, $seedFile, false);
        }

        if ($result['success']) {
            output("  ✓ Demo data installed ({$result['executed']} statements)\n", COLOR_GREEN);
        } else {
            output("  ⚠ Demo data installation completed with errors\n", COLOR_YELLOW);
            output("    Executed: {$result['executed']} statements, Errors: {$result['errors']}\n", COLOR_YELLOW);
            if (!empty($result['error_details'])) {
                foreach (array_slice($result['error_details'], 0, 3) as $err) {
                    output("    - {$err['error_message']}\n", COLOR_RED);
                }
                if (count($result['error_details']) > 3) {
                    output("    ... and " . (count($result['error_details']) - 3) . " more errors\n", COLOR_RED);
                }
            }
            if (!empty($result['log_file'])) {
                output("  See log file for details: {$result['log_file']}\n", COLOR_YELLOW);
            }
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

// Determine 2FA status message
$twoFactorStatus = 'Disabled';
if ($twoFactorEnabled) {
    $twoFactorStatus = 'Enabled ✓';
} elseif ($twoFactorPending) {
    $twoFactorStatus = 'Setup required on first login';
}

output("  ┌──────────────────────────────────────────────────────────┐\n", COLOR_CYAN);
output("  │  Configuration Summary                                   │\n", COLOR_CYAN);
output("  ├──────────────────────────────────────────────────────────┤\n", COLOR_CYAN);
output("  │  URL: {$appUrl}\n", COLOR_CYAN);
output("  │  Database: {$dbName}@{$dbHost}\n", COLOR_CYAN);
if ($tablePrefix !== '') {
    output("  │  Table Prefix: {$tablePrefix}\n", COLOR_CYAN);
}
output("  │  Admin Email: {$adminEmail}\n", COLOR_CYAN);
output("  │  2FA Status: {$twoFactorStatus}\n", COLOR_CYAN);
output("  └──────────────────────────────────────────────────────────┘\n", COLOR_CYAN);

output("\n  Next Steps:\n", COLOR_BOLD);
output("  1. Run: composer install\n");
output("  2. Run: npm install && npm run build\n");
output("  3. Configure your web server to point to the /public directory\n");
output("  4. Access your application at: {$appUrl}\n");
if ($twoFactorPending) {
    output("  5. Complete 2FA setup on your first login\n");
}

if ($tablePrefix !== '') {
    output("\n  Important: Add this to your .env file:\n", COLOR_YELLOW);
    output("  DB_PREFIX={$tablePrefix}\n", COLOR_BOLD);
}

output("\n  For future database updates, run: php upgrade.php\n\n", COLOR_YELLOW);
