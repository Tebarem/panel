<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources\Console;

use Illuminate\Console\Command;
use Pterodactyl\Support\JsonValueGuard;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceSyncService;

/**
 * Synchronizes every egg source, or only the one passed with --id. Scheduled hourly by SourcesRegistrar.
 */
final class SyncEggSourcesCommand extends Command
{
    protected $signature = 'ramjet-eggs:sync {--id= : Only synchronize the egg source with this ID.}';

    protected $description = 'Synchronize eggs from their configured egg sources (GitHub, GitLab or URL).';

    public function handle(EggSourceSyncService $sync): int
    {
        $option = $this->option('id');
        $id = $option === null ? null : JsonValueGuard::integer($option);

        $query = EggSource::query()->orderBy('id');
        if ($id !== null) {
            $query->where('id', $id);
        }

        $sources = $query->get();
        if ($sources->isEmpty()) {
            $this->warn('No egg sources were found to synchronize.');

            return $id === null ? self::SUCCESS : self::FAILURE;
        }

        $failed = false;
        foreach ($sources as $source) {
            $this->line("Synchronizing egg source #{$source->id} ({$source->name})...");

            $result = $sync->handle($source);
            foreach ($result['files'] as $file) {
                $this->line(sprintf(
                    '  [%s] %s%s',
                    $file['status'],
                    $file['path'],
                    $file['status'] === 'failed' ? ' - '.$file['message'] : '',
                ));
            }

            if ($result['error'] !== null) {
                $failed = true;
                $this->error('  '.$result['error']);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
