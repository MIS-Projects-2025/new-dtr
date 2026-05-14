<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FingerprintTemplate;
use App\Services\FingerprintService;

class BackfillFingerprintFmd extends Command
{
    protected $signature   = 'fingerprint:backfill-fmd';
    protected $description = 'Re-extract SourceAFIS FMD for existing fingerprint templates';

    public function handle(FingerprintService $service): void
    {
        $rows = FingerprintTemplate::where('is_active', 1)
            ->whereNull('fmd_data')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to backfill.');
            return;
        }

        $this->info("Backfilling {$rows->count()} templates…");
        $bar = $this->output->createProgressBar($rows->count());

        foreach ($rows as $row) {
            try {
                $fmd = $service->extractFmd($row->template_data);
                $row->update(['fmd_data' => $fmd]);
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Failed ID {$row->id} ({$row->employid} finger {$row->finger_index}): {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');
    }
}