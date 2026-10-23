<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'media_type' => fn (Blueprint $table) => $table->string('media_type', 20)->default('image')->after('mime_type'),
            'source_width' => fn (Blueprint $table) => $table->unsignedInteger('source_width')->nullable()->after('height'),
            'source_height' => fn (Blueprint $table) => $table->unsignedInteger('source_height')->nullable()->after('source_width'),
            'duration_ms' => fn (Blueprint $table) => $table->unsignedBigInteger('duration_ms')->nullable()->after('source_height'),
            'processing_stage' => fn (Blueprint $table) => $table->string('processing_stage', 40)->nullable()->after('status'),
            'progress_percent' => fn (Blueprint $table) => $table->unsignedTinyInteger('progress_percent')->default(0)->after('processing_stage'),
            'total_chunks' => fn (Blueprint $table) => $table->unsignedInteger('total_chunks')->default(0)->after('progress_percent'),
            'next_chunk' => fn (Blueprint $table) => $table->unsignedInteger('next_chunk')->default(0)->after('total_chunks'),
            'batch_uuid' => fn (Blueprint $table) => $table->uuid('batch_uuid')->nullable()->after('next_chunk'),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('codingfor_imagehost_assets', $column)) {
                Schema::table('codingfor_imagehost_assets', $definition);
            }
        }

        Schema::table('codingfor_imagehost_assets', function (Blueprint $table): void {
            $table->index(['created_by', 'batch_uuid'], 'codingfor_imagehost_assets_owner_batch_index');
            $table->index(['status', 'updated_at'], 'codingfor_imagehost_assets_status_updated_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('codingfor_imagehost_assets')) {
            return;
        }

        Schema::table('codingfor_imagehost_assets', function (Blueprint $table): void {
            $table->dropIndex('codingfor_imagehost_assets_owner_batch_index');
            $table->dropIndex('codingfor_imagehost_assets_status_updated_index');
        });

        $columns = [
            'media_type',
            'source_width',
            'source_height',
            'duration_ms',
            'processing_stage',
            'progress_percent',
            'total_chunks',
            'next_chunk',
            'batch_uuid',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('codingfor_imagehost_assets', $column)) {
                Schema::table('codingfor_imagehost_assets', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
