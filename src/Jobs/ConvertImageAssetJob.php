<?php

namespace Modules\Codingfor\Imagehost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Codingfor\Imagehost\Services\ImageHostService;

class ConvertImageAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $assetId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ImageHostService $service): void
    {
        $service->process($this->assetId);
    }
}
