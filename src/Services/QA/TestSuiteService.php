<?php

namespace App\Services\QA;

use App\Database\Connection;
use RuntimeException;

class TestSuiteService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string,string>
     */
    public function runAll(): array
    {
        $commands = [
            'unit' => 'vendor/bin/phpunit --testsuite=Unit',
            'feature' => 'vendor/bin/phpunit --testsuite=Feature',
            'permissions' => 'vendor/bin/phpunit --group=permissions',
        ];

        $results = [];
        foreach ($commands as $name => $command) {
            $results[$name] = $this->runCommand($command);
        }

        return $results;
    }

    public function seedFixtures(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->exec("INSERT INTO test_fixtures (name, created_at) VALUES ('base', NOW()) ON DUPLICATE KEY UPDATE created_at = NOW()");
    }

    private function runCommand(string $command): string
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("Test command failed: {$command} => " . implode("\n", $output));
        }

        return implode("\n", $output);
    }
}
