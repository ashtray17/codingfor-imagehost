<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codingfor_imagehost_assets', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('이미지 자산 고유번호');
            $table->unsignedBigInteger('created_by')->index()->comment('업로드 사용자 ID');
            $table->string('hash', 12)->unique()->comment('공개 URL 식별자');
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('original_size')->default(0);
            $table->unsignedBigInteger('output_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->string('temp_path', 500)->nullable();
            $table->json('variants')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_by', 'created_at'], 'codingfor_imagehost_assets_user_created_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('codingfor_imagehost_assets', function (Blueprint $table) {
                $table->comment('Codingfor 이미지 호스팅 자산');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('codingfor_imagehost_assets');
    }
};
