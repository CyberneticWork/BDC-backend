<?php

namespace App\Console\Commands;

use App\Services\EmployeeExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportEmployeesFromExcel extends Command
{
    protected $signature = 'employees:import-excel
                            {file? : Path to the Excel file}
                            {--dry-run : Parse and validate without writing to the database}
                            {--force-local : Required override if DB_HOST is not localhost (still blocked for remote hosts)}';

    protected $description = 'Import employees from the transposed Excel sheet into the LOCAL database only';

    public function handle(EmployeeExcelImportService $importService): int
    {
        $this->assertLocalDatabase();

        $file = $this->argument('file')
            ?: 'C:/Users/imesh/Downloads/Company & Employee Personal Details.xlsx';

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Employee Excel Import');
        $this->line('File      : ' . $file);
        $this->line('Database  : ' . config('database.connections.mysql.database'));
        $this->line('DB Host   : ' . config('database.connections.mysql.host'));
        $this->line('Mode      : ' . ($dryRun ? 'DRY RUN (no changes)' : 'LIVE IMPORT'));
        $this->newLine();

        if (!$dryRun && !$this->confirm('Proceed with importing employees into the local database?', true)) {
            $this->warn('Import cancelled.');
            return self::SUCCESS;
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
                ['Total in Excel', $summary['total']],
                ['Created', $summary['created']],
                ['Skipped (already exists)', $summary['skipped']],
                ['Failed', $summary['failed']],
            ]
        );

        $this->newLine();
        $this->info('Details:');
        foreach ($summary['details'] as $row) {
            $this->line(sprintf(
                '[%s] %s — %s',
                strtoupper($row['status']),
                $row['name'],
                $row['reason']
            ));
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Hard safety guard: refuse to run against remote / production databases.
     */
    private function assertLocalDatabase(): void
    {
        $host = strtolower((string) config('database.connections.mysql.host'));
        $database = strtolower((string) config('database.connections.mysql.database'));
        $appUrl = strtolower((string) config('app.url'));

        $localHosts = ['127.0.0.1', 'localhost', '::1'];
        $blockedHostPatterns = [
            'cyberneticde.site',
            'amazonaws.com',
            'digitalocean',
            'azure',
            'cloud',
        ];
        $blockedDbPatterns = [
            'apis_spmhr',
            'apispmhr',
            'production',
            'prod_',
        ];

        if (!in_array($host, $localHosts, true)) {
            $this->error("Blocked: DB_HOST must be local (127.0.0.1 or localhost). Current host: {$host}");
            $this->line('Update your .env to point to your local MySQL before running this import.');
            exit(self::FAILURE);
        }

        foreach ($blockedHostPatterns as $pattern) {
            if (str_contains($host, $pattern) || str_contains($appUrl, $pattern)) {
                $this->error("Blocked: Detected remote/production host pattern ({$pattern}).");
                exit(self::FAILURE);
            }
        }

        foreach ($blockedDbPatterns as $pattern) {
            if (str_contains($database, $pattern)) {
                $this->error("Blocked: Database name looks like a remote/production database ({$database}).");
                $this->line('Use a local database name such as hrm_backend in your .env file.');
                exit(self::FAILURE);
            }
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot connect to local database: ' . $e->getMessage());
            $this->line('Ensure MySQL is running and your .env has correct local credentials.');
            exit(self::FAILURE);
        }
    }
}
