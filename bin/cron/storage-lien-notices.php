<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Support\Env;

$env = new Env(__DIR__ . '/../../.env');

$dbConfig = [
    'driver' => $env->get('DB_DRIVER', 'mysql'),
    'host' => $env->get('DB_HOST', '127.0.0.1'),
    'port' => (int) $env->get('DB_PORT', 3306),
    'database' => $env->get('DB_DATABASE', 'phparm'),
    'username' => $env->get('DB_USERNAME', 'root'),
    'password' => $env->get('DB_PASSWORD', ''),
    'charset' => $env->get('DB_CHARSET', 'utf8mb4'),
];

$connection = new Connection($dbConfig);
$pdo = $connection->pdo();

$sql = <<<SQL
    SELECT
        impound_cases.id,
        impound_cases.case_number,
        impound_cases.state_code,
        impound_cases.impound_date,
        DATEDIFF(CURDATE(), DATE(impound_cases.impound_date)) AS days_held,
        COALESCE(storage_rates.lien_notice_days, 0) AS lien_notice_days
    FROM impound_cases
    LEFT JOIN (
        SELECT rates.*
        FROM storage_rates rates
        INNER JOIN (
            SELECT state_code, MAX(effective_date) AS effective_date
            FROM storage_rates
            WHERE status = 'active'
            GROUP BY state_code
        ) latest
            ON latest.state_code = rates.state_code
            AND latest.effective_date = rates.effective_date
        WHERE rates.status = 'active'
    ) storage_rates ON storage_rates.state_code = impound_cases.state_code
    WHERE impound_cases.released_at IS NULL
      AND impound_cases.status NOT IN ('closed', 'released')
      AND COALESCE(storage_rates.lien_notice_days, 0) > 0
      AND DATEDIFF(CURDATE(), DATE(impound_cases.impound_date)) >= COALESCE(storage_rates.lien_notice_days, 0)
      AND NOT EXISTS (
          SELECT 1
          FROM lien_notices
          WHERE lien_notices.impound_case_id = impound_cases.id
            AND lien_notices.notice_type = 'Lien Notice'
      )
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute();
$cases = $stmt->fetchAll(\PDO::FETCH_ASSOC);
$stmt->closeCursor();

if ($cases === []) {
    echo "No impound cases reached the lien notice threshold.\n";
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO lien_notices (impound_case_id, notice_type, notice_date, due_date, status)
     VALUES (?, ?, ?, ?, ?)'
);

$noticeDate = date('Y-m-d');
$dueDate = date('Y-m-d', strtotime('+10 days'));
$created = 0;

foreach ($cases as $case) {
    $insert->execute([
        (int) $case['id'],
        'Lien Notice',
        $noticeDate,
        $dueDate,
        'ready',
    ]);
    $created++;
}

$insert->closeCursor();

echo sprintf(
    'Created %d lien notice(s) for cases beyond threshold on %s.',
    $created,
    $noticeDate
) . "\n";
