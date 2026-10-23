<?php

namespace Modules\Codingfor\Imagehost\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Codingfor\Imagehost\Jobs\ConvertImageAssetJob;
use Modules\Codingfor\Imagehost\Models\ImageAsset;
use Modules\Codingfor\Imagehost\Repositories\Contracts\ImageAssetRepositoryInterface;
use RuntimeException;

class ImageHostService
{
    public function __construct(
        private ImageAssetRepositoryInterface $repository,
        private StorageInterface $storage,
        private WebpConverter $converter,
        private MediaPolicy $policy
    ) {}

    public function upload(UploadedFile $file, ?int $userId, ?string $guestTokenHash): ImageAsset
    {
        $dailyLimit = max(1, (int) g7_module_settings('codingfor-imagehost', 'daily_upload_limit', 200));

        if ($this->repository->countTodayForOwner($userId, $guestTokenHash) >= $dailyLimit) {
            throw ValidationException::withMessages([
                'file' => __('codingfor-imagehost::messages.daily_limit'),
            ]);
        }

        $mimeType = strtolower((string) ($file->getMimeType() ?: ''));
        $mediaType = $this->policy->mediaTypeForMime($mimeType);
        if ($mediaType !== MediaPolicy::IMAGE) {
            throw ValidationException::withMessages([
                'file' => __('codingfor-imagehost::validation.file_mimes'),
            ]);
        }

        $this->policy->assertUploadAllowed($userId, $mediaType, (int) $file->getSize());
        $source = $this->converter->analyze($file->getRealPath());
        $this->policy->assertImageDimensions($userId, $source['width'], $source['height']);

        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $tempPath = date('Y/m/d').'/'.Str::uuid().'.'.$extension;

        $sourceHandle = fopen($file->getRealPath(), 'rb');
        if ($sourceHandle === false) {
            throw new RuntimeException('source_open_failed');
        }

        try {
            if (! $this->storage->put('temp', $tempPath, $sourceHandle)) {
                throw new RuntimeException('source_store_failed');
            }
        } finally {
            fclose($sourceHandle);
        }

        $asset = $this->repository->create([
            'created_by' => $userId ?? 0,
            'guest_token_hash' => $userId === null ? $guestTokenHash : null,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType ?: 'application/octet-stream',
            'media_type' => $mediaType,
            'original_size' => (int) $file->getSize(),
            'source_width' => $source['width'],
            'source_height' => $source['height'],
            'status' => 'queued',
            'processing_stage' => 'queued',
            'temp_path' => $tempPath,
        ]);

        ConvertImageAssetJob::dispatch($asset->id)->onQueue('imagehost');

        return $asset->fresh();
    }

    public function beginUpload(
        int $userId,
        string $filename,
        string $mimeType,
        int $size,
        int $totalChunks,
        ?string $batchUuid = null,
    ): ImageAsset {
        $dailyLimit = max(1, (int) g7_module_settings('codingfor-imagehost', 'daily_upload_limit', 200));
        if ($this->repository->countTodayForOwner($userId, null) >= $dailyLimit) {
            throw ValidationException::withMessages([
                'file' => __('codingfor-imagehost::messages.daily_limit'),
            ]);
        }

        $mediaType = $this->policy->mediaTypeForMime($mimeType);
        if ($mediaType === null) {
            throw ValidationException::withMessages([
                'mime_type' => __('codingfor-imagehost::validation.file_mimes'),
            ]);
        }

        $this->policy->assertUploadAllowed($userId, $mediaType, $size);
        $chunkSize = max(1, (int) config('codingfor-imagehost.limits.chunk_size', 16 * 1024 * 1024));
        $maxChunks = max(1, (int) config('codingfor-imagehost.limits.max_chunks', 256));
        $expectedChunks = (int) ceil($size / $chunkSize);

        if ($totalChunks !== $expectedChunks || $totalChunks > $maxChunks) {
            throw ValidationException::withMessages([
                'total_chunks' => __('codingfor-imagehost::validation.chunk_count_invalid'),
            ]);
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $tempPath = date('Y/m/d').'/'.Str::uuid().'.'.$extension;

        return $this->repository->create([
            'created_by' => $userId,
            'guest_token_hash' => null,
            'original_filename' => $filename,
            'mime_type' => strtolower($mimeType),
            'media_type' => $mediaType,
            'original_size' => $size,
            'status' => 'uploading',
            'processing_stage' => 'uploading',
            'progress_percent' => 0,
            'total_chunks' => $totalChunks,
            'next_chunk' => 0,
            'batch_uuid' => $batchUuid ?: (string) Str::uuid(),
            'temp_path' => $tempPath,
        ]);
    }

    public function appendChunk(ImageAsset $asset, UploadedFile $chunk, int $chunkIndex): ImageAsset
    {
        if ($asset->status !== 'uploading' || $asset->temp_path === null) {
            throw ValidationException::withMessages([
                'chunk_index' => __('codingfor-imagehost::messages.upload_session_closed'),
            ]);
        }

        if ($chunkIndex !== (int) $asset->next_chunk) {
            throw ValidationException::withMessages([
                'chunk_index' => __('codingfor-imagehost::validation.chunk_order_invalid'),
            ]);
        }

        $basePath = $this->storage->getBasePath('temp');
        $targetPath = $basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $asset->temp_path);
        $targetDirectory = dirname($targetPath);
        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0700, true) && ! is_dir($targetDirectory)) {
            throw new RuntimeException('chunk_directory_failed');
        }

        $sourceHandle = fopen($chunk->getRealPath(), 'rb');
        $targetHandle = fopen($targetPath, 'ab');
        if ($sourceHandle === false || $targetHandle === false) {
            if (is_resource($sourceHandle)) fclose($sourceHandle);
            if (is_resource($targetHandle)) fclose($targetHandle);
            throw new RuntimeException('chunk_open_failed');
        }

        try {
            if (stream_copy_to_stream($sourceHandle, $targetHandle) === false) {
                throw new RuntimeException('chunk_store_failed');
            }
        } finally {
            fclose($sourceHandle);
            fclose($targetHandle);
        }

        $nextChunk = $chunkIndex + 1;
        $asset->update([
            'next_chunk' => $nextChunk,
            'progress_percent' => min(20, (int) floor($nextChunk / max(1, (int) $asset->total_chunks) * 20)),
        ]);

        return $asset->fresh();
    }

    public function completeUpload(ImageAsset $asset): ImageAsset
    {
        if ($asset->status !== 'uploading') {
            return $asset->fresh();
        }

        if ((int) $asset->next_chunk !== (int) $asset->total_chunks) {
            throw ValidationException::withMessages([
                'chunks' => __('codingfor-imagehost::validation.upload_incomplete'),
            ]);
        }

        $inputPath = $this->storage->getBasePath('temp').DIRECTORY_SEPARATOR.$asset->temp_path;
        if (! is_file($inputPath) || (int) filesize($inputPath) !== (int) $asset->original_size) {
            throw ValidationException::withMessages([
                'file' => __('codingfor-imagehost::validation.upload_incomplete'),
            ]);
        }

        $asset->update([
            'status' => 'queued',
            'processing_stage' => 'queued',
            'progress_percent' => 20,
        ]);
        ConvertImageAssetJob::dispatch($asset->id)->onQueue('imagehost');

        return $asset->fresh();
    }

    public function process(int $assetId): void
    {
        $asset = $this->repository->findById($assetId);
        if (! $asset || ! $asset->temp_path || ! $this->repository->claimForProcessing($assetId)) {
            return;
        }

        $asset->refresh();
        $inputPath = $this->storage->getBasePath('temp').DIRECTORY_SEPARATOR.$asset->temp_path;
        $workingDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-imagehost-'.Str::uuid();
        $tempPath = $asset->temp_path;
        $publishedPaths = [];

        if (! is_file($inputPath)) {
            $asset->update([
                'status' => 'failed',
                'processing_stage' => 'failed',
                'progress_percent' => 0,
                'error_code' => 'source_missing',
                'temp_path' => null,
            ]);

            return;
        }

        try {
            if (! mkdir($workingDirectory, 0700, true) && ! is_dir($workingDirectory)) {
                throw new RuntimeException('working_directory_failed');
            }

            $asset->update(['processing_stage' => 'probing', 'progress_percent' => 25]);
            $this->processImage($asset, $inputPath, $workingDirectory, $publishedPaths);

            $this->storage->delete('temp', $tempPath);
        } catch (\Throwable $exception) {
            foreach ($publishedPaths as $publishedPath) {
                $this->storage->withDisk('public')->delete('images', $publishedPath);
            }

            $this->storage->delete('temp', $tempPath);

            $asset->update([
                'status' => 'failed',
                'processing_stage' => 'failed',
                'progress_percent' => 0,
                'error_code' => $this->safeErrorCode($exception->getMessage()),
                'temp_path' => null,
            ]);
            Log::warning('Image host conversion failed', [
                'asset_id' => $asset->id,
                'error_code' => $exception->getMessage(),
            ]);
        } finally {
            $this->removeDirectory($workingDirectory);
        }
    }

    private function processImage(ImageAsset $asset, string $inputPath, string $workingDirectory, array &$publishedPaths): void
    {
        $source = $this->converter->analyze($inputPath);
        $this->policy->assertImageDimensions((int) $asset->created_by === 0 ? null : (int) $asset->created_by, $source['width'], $source['height']);
        $quality = max(60, min(95, (int) g7_module_settings('codingfor-imagehost', 'quality', 82)));
        $displayEdge = min(2560, max(320, (int) g7_module_settings('codingfor-imagehost', 'display_width', 2560)));
        $thumbEdge = min(640, max(160, (int) g7_module_settings('codingfor-imagehost', 'thumb_width', 640)));
        $displayOutput = $workingDirectory.DIRECTORY_SEPARATOR.'display.webp';
        $thumbOutput = $workingDirectory.DIRECTORY_SEPARATOR.'thumb.webp';

        $asset->update([
            'source_width' => $source['width'],
            'source_height' => $source['height'],
            'processing_stage' => 'converting',
            'progress_percent' => 40,
        ]);
        $display = $this->converter->convert($inputPath, $displayOutput, $displayEdge, $quality, $source);
        $asset->update(['progress_percent' => 65]);
        $thumb = $this->converter->convert($inputPath, $thumbOutput, $thumbEdge, max(60, $quality - 7), $source);
        $publicStorage = $this->storage->withDisk('public');
        $datePath = date('Y/m/d');
        $displayPath = "{$datePath}/".Str::uuid().'.webp';
        $thumbPath = "{$datePath}/".Str::uuid().'.webp';

        $this->putLocalFile($publicStorage, 'images', $displayPath, $displayOutput);
        $publishedPaths[] = $displayPath;
        $this->putLocalFile($publicStorage, 'images', $thumbPath, $thumbOutput);
        $publishedPaths[] = $thumbPath;

        $asset->update([
            'status' => 'ready',
            'processing_stage' => 'complete',
            'progress_percent' => 100,
            'temp_path' => null,
            'width' => $display['width'],
            'height' => $display['height'],
            'output_size' => $display['size'] + $thumb['size'],
            'variants' => [
                'display' => ['disk' => $publicStorage->getDisk(), 'path' => $displayPath, 'mime_type' => 'image/webp', ...$display],
                'thumb' => ['disk' => $publicStorage->getDisk(), 'path' => $thumbPath, 'mime_type' => 'image/webp', ...$thumb],
            ],
        ]);
    }

    private function putLocalFile(StorageInterface $storage, string $category, string $path, string $localPath): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('output_open_failed');
        }

        try {
            if (! $storage->put($category, $path, $handle)) {
                throw new RuntimeException('output_store_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function safeErrorCode(string $message): string
    {
        $known = [
            'source_missing',
            'source_open_failed',
            'source_store_failed',
            'chunk_directory_failed',
            'chunk_open_failed',
            'chunk_store_failed',
            'working_directory_failed',
            'vipsheader_failed',
            'webp_conversion_failed',
            'webp_validation_failed',
            'output_open_failed',
            'output_store_failed',
            'too_many_pixels',
            'invalid_dimensions',
            'animated_or_multipage',
        ];

        return in_array($message, $known, true) ? $message : 'conversion_failed';
    }

    public function recentForOwner(?int $userId, ?string $guestTokenHash): Collection
    {
        return $this->repository->recentForOwner($userId, $guestTokenHash)->map(fn (ImageAsset $asset) => $this->payload($asset));
    }

    public function batchForOwner(array $ids, ?int $userId, ?string $guestTokenHash): Collection
    {
        return $this->repository->findManyForOwner($ids, $userId, $guestTokenHash)
            ->map(fn (ImageAsset $asset) => $this->payload($asset));
    }

    public function findForOwner(int $id, ?int $userId, ?string $guestTokenHash): ?ImageAsset
    {
        return $this->repository->findForOwner($id, $userId, $guestTokenHash);
    }

    public function delete(ImageAsset $asset): void
    {
        $variants = is_array($asset->variants) ? $asset->variants : [];
        foreach ($variants as $variant) {
            if (is_array($variant) && ! empty($variant['path'])) {
                $this->storage->withDisk((string) ($variant['disk'] ?? 'public'))->delete('images', $variant['path']);
            }
        }

        if ($asset->temp_path) {
            $this->storage->delete('temp', $asset->temp_path);
        }

        $asset->delete();
    }

    public function payload(ImageAsset $asset): array
    {
        $ready = $asset->isReady();
        $displayUrl = $ready ? $this->assetUrl($asset->hash, 'display') : null;
        $thumbUrl = $ready ? $this->assetUrl($asset->hash, 'thumb') : null;

        return [
            'id' => $asset->id,
            'hash' => $asset->hash,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'media_type' => $asset->media_type,
            'original_size' => $asset->original_size,
            'output_size' => $asset->output_size,
            'width' => $asset->width,
            'height' => $asset->height,
            'source_width' => $asset->source_width,
            'source_height' => $asset->source_height,
            'status' => $asset->status,
            'processing_stage' => $asset->processing_stage,
            'progress_percent' => $asset->progress_percent,
            'error_code' => $asset->error_code,
            'display_url' => $displayUrl,
            'thumb_url' => $thumbUrl,
            'embed_html' => $ready ? '<img src="'.e($displayUrl).'" alt="" loading="lazy">' : null,
            'created_at' => $asset->created_at,
        ];
    }

    public function serveVariant(string $hash, string $variantKey): ?array
    {
        $asset = $this->repository->findByHash($hash);
        if (! $asset || ! $asset->isReady()) {
            return null;
        }

        $variant = $asset->variants[$variantKey] ?? null;
        if (! is_array($variant) || empty($variant['path'])) {
            return null;
        }

        $storage = $this->storage->withDisk((string) ($variant['disk'] ?? 'public'));
        $mimeType = (string) ($variant['mime_type'] ?? 'image/webp');
        $filename = 'image.webp';
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) ($variant['size'] ?? 0),
            'Content-Disposition' => 'inline',
        ];
        $response = $storage->response('images', $variant['path'], $filename, $headers);

        if (! $response) {
            return null;
        }

        return [
            'response' => $response,
            'etag_source' => implode('|', [
                $variant['disk'] ?? 'public',
                $variant['path'],
                (string) $asset->updated_at?->getTimestamp(),
                (string) ($variant['size'] ?? 0),
                $mimeType,
            ]),
            'cache_ttl' => max(3600, (int) g7_module_settings('codingfor-imagehost', 'cache_ttl', 86400)),
        ];
    }

    public function prune(int $days, int $purgeDays, int $limit): array
    {
        $stale = $this->repository->staleProcessing($days, $limit);
        foreach ($stale as $asset) {
            if ($asset->temp_path) {
                $this->storage->delete('temp', $asset->temp_path);
            }
            $asset->update([
                'status' => 'failed',
                'processing_stage' => 'failed',
                'progress_percent' => 0,
                'error_code' => 'processing_timeout',
                'temp_path' => null,
            ]);
        }

        $deleted = $this->repository->deletedOlderThan($purgeDays, $limit);
        foreach ($deleted as $asset) {
            $this->deleteFiles($asset);
            $asset->forceDelete();
        }

        return ['stale' => $stale->count(), 'purged' => $deleted->count()];
    }

    private function deleteFiles(ImageAsset $asset): void
    {
        $variants = is_array($asset->variants) ? $asset->variants : [];
        foreach ($variants as $variant) {
            if (is_array($variant) && ! empty($variant['path'])) {
                $this->storage->withDisk((string) ($variant['disk'] ?? 'public'))->delete('images', $variant['path']);
            }
        }

        if ($asset->temp_path) {
            $this->storage->delete('temp', $asset->temp_path);
        }
    }

    private function assetUrl(string $hash, string $variantKey): string
    {
        $suffix = $variantKey === 'thumb' ? '.thumb.webp' : '.webp';
        $path = '/api/modules/codingfor-imagehost/i/'.$hash.$suffix;
        $publicUrl = trim((string) config('codingfor-imagehost.public_url', ''));

        if ($publicUrl !== '') {
            $parts = parse_url($publicUrl);
            $validScheme = is_array($parts)
                && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
            $validHost = is_array($parts) && ! empty($parts['host']);
            $hasUserInfo = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
            $hasQueryOrFragment = is_array($parts) && (isset($parts['query']) || isset($parts['fragment']));

            if ($validScheme && $validHost && ! $hasUserInfo && ! $hasQueryOrFragment) {
                return rtrim($publicUrl, '/').$path;
            }

            Log::warning('Invalid image host public URL; falling back to the request URL.', [
                'url' => $publicUrl,
            ]);
        }

        return url($path);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
