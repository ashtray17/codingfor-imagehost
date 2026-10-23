<?php

namespace Modules\Codingfor\Imagehost\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class ImageAsset extends Model
{
    use SoftDeletes;

    protected $table = 'codingfor_imagehost_assets';

    protected $fillable = [
        'created_by',
        'guest_token_hash',
        'hash',
        'original_filename',
        'mime_type',
        'media_type',
        'original_size',
        'output_size',
        'width',
        'height',
        'source_width',
        'source_height',
        'status',
        'processing_stage',
        'progress_percent',
        'total_chunks',
        'next_chunk',
        'batch_uuid',
        'temp_path',
        'variants',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'original_size' => 'integer',
            'output_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'source_width' => 'integer',
            'source_height' => 'integer',
            'progress_percent' => 'integer',
            'total_chunks' => 'integer',
            'next_chunk' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            $asset->hash ??= strtolower(Str::random(12));
        });
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

}
