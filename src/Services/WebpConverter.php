<?php

namespace Modules\Codingfor\Imagehost\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class WebpConverter
{
    public function analyze(string $inputPath): array
    {
        $result = Process::timeout(60)->run([
            $this->binary('vipsheader_binary', 'vipsheader'),
            '-a',
            $inputPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('vipsheader_failed');
        }

        $details = $result->output();
        preg_match('/^width:\s*(\d+)/mi', $details, $widthMatch);
        preg_match('/^height:\s*(\d+)/mi', $details, $heightMatch);
        preg_match('/^n-pages:\s*(\d+)/mi', $details, $pagesMatch);

        $width = (int) ($widthMatch[1] ?? 0);
        $height = (int) ($heightMatch[1] ?? 0);
        $pages = (int) ($pagesMatch[1] ?? 1);

        if ($width < 1 || $height < 1) {
            throw new RuntimeException('invalid_dimensions');
        }

        $maxPixels = max(1, (int) config('codingfor-imagehost.limits.member_image_max_pixels', 250_000_000));
        if ($width * $height > $maxPixels) {
            throw new RuntimeException('too_many_pixels');
        }

        if ($pages > 1) {
            throw new RuntimeException('animated_or_multipage');
        }

        return [
            'width' => $width,
            'height' => $height,
            'pages' => $pages,
        ];
    }

    public function convert(string $inputPath, string $outputPath, int $maxEdge, int $quality, array $source): array
    {
        $longest = max($source['width'], $source['height']);
        $targetEdge = min($maxEdge, $longest);
        $outputWithOptions = $outputPath.'[Q='.max(1, min(100, $quality)).',strip]';
        $arguments = [
            $this->binary('vips_binary', 'vips'),
            'thumbnail',
            $inputPath,
            $outputWithOptions,
            (string) $targetEdge,
            '--size=down',
        ];

        $result = Process::timeout(180)->run($arguments);

        if (! $result->successful() || ! is_file($outputPath)) {
            throw new RuntimeException('webp_conversion_failed');
        }

        $image = @getimagesize($outputPath);
        if ($image === false || ($image['mime'] ?? null) !== 'image/webp') {
            throw new RuntimeException('webp_validation_failed');
        }

        return [
            'width' => (int) $image[0],
            'height' => (int) $image[1],
            'size' => (int) filesize($outputPath),
        ];
    }

    private function binary(string $configKey, string $fallback): string
    {
        $binary = trim((string) config('codingfor-imagehost.'.$configKey, $fallback));

        return $binary !== '' ? $binary : $fallback;
    }
}
