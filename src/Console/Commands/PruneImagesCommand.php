<?php

namespace Modules\Codingfor\Imagehost\Console\Commands;

use Illuminate\Console\Command;
use Modules\Codingfor\Imagehost\Services\ImageHostService;

class PruneImagesCommand extends Command
{
    protected $signature = 'codingfor-imagehost:prune-temp
        {--days=1 : Days before queued/processing assets are failed}
        {--purge-days=7 : Days before deleted assets are permanently purged}
        {--limit=500 : Maximum assets to process}';

    protected $description = 'Clean up stale image-host uploads and deleted assets';

    public function handle(ImageHostService $service): int
    {
        $result = $service->prune(
            max(1, (int) $this->option('days')),
            max(1, (int) $this->option('purge-days')),
            max(1, (int) $this->option('limit'))
        );

        $this->info("Marked stale: {$result['stale']}; purged: {$result['purged']}.");

        return self::SUCCESS;
    }
}
