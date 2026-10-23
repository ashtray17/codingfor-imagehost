<?php

namespace Modules\Codingfor\Imagehost\Providers;

use App\Extension\BaseModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Codingfor\Imagehost\Console\Commands\PruneImagesCommand;
use Modules\Codingfor\Imagehost\Repositories\Contracts\ImageAssetRepositoryInterface;
use Modules\Codingfor\Imagehost\Repositories\ImageAssetRepository;
use Modules\Codingfor\Imagehost\Services\ImageHostService;

class ImagehostServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'codingfor-imagehost';

    protected array $storageServices = [
        ImageHostService::class,
    ];

    protected array $repositories = [
        ImageAssetRepositoryInterface::class => ImageAssetRepository::class,
    ];

    protected array $commands = [
        PruneImagesCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/imagehost.php', 'codingfor-imagehost');

        parent::register();
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('codingfor-imagehost-upload', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($userId !== null ? 120 : 30)
                ->by($userId !== null ? 'user:'.$userId : 'guest:'.$request->ip());
        });

        RateLimiter::for('codingfor-imagehost-chunk', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($userId !== null ? 600 : 1)
                ->by($userId !== null ? 'user:'.$userId : 'guest:'.$request->ip());
        });

        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }
    }
}
