<?php

namespace Pterodactyl\Console\Commands\EggSource;

use Illuminate\Console\Command;
use Pterodactyl\Models\EggSource;
use Pterodactyl\Services\Eggs\Sources\EggSourceSyncService;

class SyncEggSourceCommand extends Command
{
    protected $description = 'Synchronize eggs from their configured egg sources (GitHub, GitLab or URL).';

    protected $signature = 'p:egg-source:sync {--id= : Only synchronize the egg source with this ID.}';

    /**
     * SyncEggSourceCommand constructor.
     */
    public function __construct(private EggSourceSyncService $syncService)
    {
        parent::__construct();
    }

    /**
     * Synchronize every egg source, or only the one passed with --id.
     */
    public function handle(): int
    {
        $query = EggSource::query()->orderBy('id');
        if (!is_null($this->option('id'))) {
            $query->where('id', (int) $this->option('id'));
        }

        $sources = $query->get();
        if ($sources->isEmpty()) {
            $this->warn('No egg sources were found to synchronize.');

            return is_null($this->option('id')) ? self::SUCCESS : self::FAILURE;
        }

        $failed = false;
        foreach ($sources as $source) {
            $this->line("Synchronizing egg source #{$source->id} ({$source->name})...");

            $result = $this->syncService->handle($source);
            foreach ($result['files'] as $file) {
                $this->line(sprintf(
                    '  [%s] %s%s',
                    $file['status'],
                    $file['path'],
                    $file['status'] === 'failed' ? ' - ' . $file['message'] : ''
                ));
            }

            if (!is_null($result['error'])) {
                $failed = true;
                $this->error('  ' . $result['error']);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
