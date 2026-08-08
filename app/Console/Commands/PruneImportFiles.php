<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\ImportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reclaims import rows and files the normal flow could not.
 *
 * ProcessImportJob deletes its own file as soon as it writes a preview, so in
 * the healthy case this command has nothing to do. It exists for the unhealthy
 * one: if no queue worker ever runs, a queued row and its uploaded document
 * would otherwise live forever, which is the unbounded orphan the phase is
 * required to avoid.
 */
class PruneImportFiles extends Command
{
    protected $signature = 'formforge:prune-import-files';

    protected $description = 'Fail stale import jobs and delete import files that are no longer needed';

    public function handle(): int
    {
        $minutes = max(1, (int) config('formforge.import.stale_after_minutes', 60));
        $cutoff = now()->subMinutes($minutes);
        $disk = Storage::disk((string) config('formforge.uploads.disk', 'local'));

        $failed = 0;
        $deleted = 0;

        ImportJob::query()
            ->whereIn('status', [ImportStatus::Queued, ImportStatus::Processing])
            ->where('created_at', '<', $cutoff)
            ->lazyById()
            ->each(function (ImportJob $job) use ($disk, &$failed, &$deleted): void {
                $job->forceFill([
                    'status' => ImportStatus::Failed,
                    'errors' => ['message' => 'The import timed out before it could be processed.'],
                ])->save();

                $failed++;

                if ($this->delete($disk, $job)) {
                    $deleted++;
                }
            });

        // Backstop for a worker that died between deleting the file and
        // recording that it had.
        ImportJob::query()
            ->whereIn('status', [ImportStatus::Preview, ImportStatus::Committed, ImportStatus::Failed])
            ->lazyById()
            ->each(function (ImportJob $job) use ($disk, &$deleted): void {
                if ($this->delete($disk, $job)) {
                    $deleted++;
                }
            });

        $this->info("Failed {$failed} stale import job(s) and deleted {$deleted} file(s).");

        return self::SUCCESS;
    }

    private function delete(mixed $disk, ImportJob $job): bool
    {
        try {
            if ($job->disk_path === null || $job->disk_path === '' || ! $disk->exists($job->disk_path)) {
                return false;
            }

            return $disk->delete($job->disk_path);
        } catch (Throwable) {
            // A single unreadable path must not stop the sweep.
            return false;
        }
    }
}
