<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codingfor_imagehost_assets', function (Blueprint $table): void {
            $table->string('guest_token_hash', 64)->nullable()->index()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('codingfor_imagehost_assets', function (Blueprint $table): void {
            $table->dropIndex(['guest_token_hash']);
            $table->dropColumn('guest_token_hash');
        });
    }
};
