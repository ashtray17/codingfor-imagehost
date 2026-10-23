<?php

namespace Modules\Codingfor\Imagehost\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Codingfor\Imagehost\Models\ImageAsset;

interface ImageAssetRepositoryInterface
{
    public function create(array $attributes): ImageAsset;

    public function findById(int $id): ?ImageAsset;

    public function findByHash(string $hash): ?ImageAsset;

    public function findForOwner(int $id, ?int $userId, ?string $guestTokenHash): ?ImageAsset;

    public function findManyForOwner(array $ids, ?int $userId, ?string $guestTokenHash): Collection;

    public function recentForOwner(?int $userId, ?string $guestTokenHash, int $limit = 50): Collection;

    public function countTodayForOwner(?int $userId, ?string $guestTokenHash): int;

    public function staleProcessing(int $days, int $limit): Collection;

    public function deletedOlderThan(int $days, int $limit): Collection;

    public function claimForProcessing(int $id): bool;
}
