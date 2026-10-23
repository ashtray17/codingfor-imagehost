<?php

namespace Modules\Codingfor\Imagehost\Repositories;

use Illuminate\Support\Collection;
use Modules\Codingfor\Imagehost\Models\ImageAsset;
use Modules\Codingfor\Imagehost\Repositories\Contracts\ImageAssetRepositoryInterface;

class ImageAssetRepository implements ImageAssetRepositoryInterface
{
    public function create(array $attributes): ImageAsset
    {
        return ImageAsset::query()->create($attributes);
    }

    public function findById(int $id): ?ImageAsset
    {
        return ImageAsset::query()->find($id);
    }

    public function findByHash(string $hash): ?ImageAsset
    {
        return ImageAsset::query()->where('hash', $hash)->first();
    }

    public function findForOwner(int $id, ?int $userId, ?string $guestTokenHash): ?ImageAsset
    {
        return $this->ownerQuery($userId, $guestTokenHash)
            ->whereKey($id)
            ->first();
    }

    public function findManyForOwner(array $ids, ?int $userId, ?string $guestTokenHash): Collection
    {
        return $this->ownerQuery($userId, $guestTokenHash)
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))
            ->latest('id')
            ->get();
    }

    public function recentForOwner(?int $userId, ?string $guestTokenHash, int $limit = 50): Collection
    {
        return $this->ownerQuery($userId, $guestTokenHash)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function countTodayForOwner(?int $userId, ?string $guestTokenHash): int
    {
        return $this->ownerQuery($userId, $guestTokenHash)
            ->whereDate('created_at', today())
            ->count();
    }

    private function ownerQuery(?int $userId, ?string $guestTokenHash)
    {
        if ($userId !== null) {
            return ImageAsset::query()->where('created_by', $userId);
        }

        if ($guestTokenHash !== null) {
            return ImageAsset::query()
                ->where('created_by', 0)
                ->where('guest_token_hash', $guestTokenHash);
        }

        return ImageAsset::query()->whereRaw('1 = 0');
    }

    public function staleProcessing(int $days, int $limit): Collection
    {
        return ImageAsset::query()
            ->whereIn('status', ['uploading', 'queued', 'processing'])
            ->where('created_at', '<', now()->subDays($days))
            ->oldest('id')
            ->limit($limit)
            ->get();
    }

    public function deletedOlderThan(int $days, int $limit): Collection
    {
        return ImageAsset::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->oldest('id')
            ->limit($limit)
            ->get();
    }

    public function claimForProcessing(int $id): bool
    {
        return ImageAsset::query()
            ->whereKey($id)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'processing_stage' => 'probing',
                'progress_percent' => 25,
                'error_code' => null,
            ]) === 1;
    }
}
