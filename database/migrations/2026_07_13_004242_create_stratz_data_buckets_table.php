<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stratz_data_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('bucket_key', 120)->index();
            $table->string('data_type', 80)->index();
            $table->unsignedTinyInteger('window_weeks')->index();
            $table->unsignedBigInteger('anchor_week')->index();
            $table->json('week_timestamps');
            $table->string('bracket_basic_id', 80)->nullable()->index();
            $table->json('hero_ids')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('payload_hash', 64)->index();
            $table->string('model_version', 80);
            $table->timestamps();

            $table->index(['draft_snapshot_id', 'data_type', 'window_weeks']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stratz_data_buckets');
    }
};
