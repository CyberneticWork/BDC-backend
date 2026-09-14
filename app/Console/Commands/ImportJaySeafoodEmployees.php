<?php

namespace App\Console\Commands;

use App\Services\JaySeafoodExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportJaySeafoodEmployees extends Command
{
    protected $signature = 'employees:import-jay-excel
                            {file? : Path to Jeaysayfood.xlsx}
                            {--purge : Delete all existing employees in the current database first}
                            {--dry-run : Parse without writing}
                            {--force : Skip confirmation}';

    protected $description = 'Replace local jay employees from the Jay Seafood Excel sheet (existing columns only)';

    public function handle(JaySeafoodExcelImportService $importService): int
    {
        $this->forceJayConnection();
        $this->assertLocalJay();

        $file = $this->argument('file')
            ?: 'C:/Users/imesh/OneDrive/Desktop/Jeaysayfood.xlsx';

        $dryRun = (bool) $this->option('dry-run');
        $purge = (bool) $this->option('purge');

        $this->info('Jay Seafood employee import');
        $this->line('File     : ' . $file);
        $this->line('Database : ' . DB::connection()->getDatabaseName());
        $this->line('Mode     : ' . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->newLine();

        if (!$dryRun && !$this->option('force') && !$this->confirm('Import into ' . DB::connection()->getDatabaseName() . '?', true)) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        if ($purge && !$dryRun) {
            $removed = $importService->purgeEmployees();
            $this->warn("Removed {$removed} existing employee records (HR users without employee_id were kept).");
        }

        try {
            $summary = $importService->import($file, $dryRun);
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Excel rows parsed', $summary['total']],
                ['Created', $summary['created']],
                ['Skipped', $summary['skipped']],
                ['Failed', $summary['failed']],
            ]
        );

        foreach ($summary['details'] as $row) {
            if ($row['status'] === 'created' || $row['status'] === 'dry-run') {
                continue;
            }
            $this->line(sprintf('[%s] %s — %s', strtoupper($row['status']), $row['name'], $row['reason']));
        }

        $this->info('Created/dry-run: ' . $summary['created']);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function forceJayConnection(): void
    {
        config([
            'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => 'jay',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function assertLocalJay(): void
    {
        $host = strtolower((string) config('database.connections.mysql.host'));
        $database = strtolower((string) DB::connection()->getDatabaseName());
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->error('Blocked: not a local database host.');
            exit(self::FAILURE);
        }
        if ($database !== 'jay') {
            $this->error("Blocked: expected database jay, connected to {$database}.");
            exit(self::FAILURE);
        }
    }
}
