<?php

namespace Modules\Codingfor\Imagehost\Services;

use Illuminate\Validation\ValidationException;

class MediaPolicy
{
    public const IMAGE = 'image';

    public function mediaTypeForMime(?string $mimeType): ?string
    {
        return in_array(strtolower((string) $mimeType), ['image/jpeg', 'image/png', 'image/webp'], true)
            ? self::IMAGE
            : null;
    }

    public function assertUploadAllowed(?int $userId, string $mediaType, int $bytes): void
    {
        $maxBytes = $userId === null
            ? (int) config('codingfor-imagehost.limits.guest_image_max_bytes')
            : (int) config('codingfor-imagehost.limits.member_image_max_bytes');

        if ($bytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => __('codingfor-imagehost::validation.file_max', [
                    'maxMB' => (int) floor($maxBytes / 1024 / 1024),
                ]),
            ]);
        }
    }

    public function assertImageDimensions(?int $userId, int $width, int $height): void
    {
        $pixels = $width * $height;
        $maxPixels = $userId === null
            ? (int) config('codingfor-imagehost.limits.guest_image_max_pixels')
            : (int) config('codingfor-imagehost.limits.member_image_max_pixels');

        if ($pixels > $maxPixels) {
            if ($userId === null) {
                throw ValidationException::withMessages([
                    'file' => __('codingfor-imagehost::messages.login_required_for_large_image'),
                ]);
            }

            throw ValidationException::withMessages([
                'file' => __('codingfor-imagehost::messages.image_resolution_too_large'),
            ]);
        }
    }

}
