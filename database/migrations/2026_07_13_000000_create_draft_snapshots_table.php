<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40);
            $table->timestamp('captured_at')->index();
            $table->unsignedBigInteger('match_id')->nullable()->index();
            $table->string('dltv_url', 2048)->nullable();
            $table->string('tournament')->nullable();
            $table->string('radiant_team')->nullable();
            $table->string('dire_team')->nullable();
            $table->json('radiant_heroes')->nullable();
            $table->json('dire_heroes')->nullable();
            $table->decimal('bookmaker_odds_radiant', 8, 3)->nullable();
            $table->decimal('bookmaker_odds_dire', 8, 3)->nullable();
            $table->json('draft_payload')->nullable();
            $table->json('stratz_payload')->nullable();
            $table->json('feature_payload')->nullable();
            $table->json('sheet_payload')->nullable();
            $table->json('book_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->string('model_version', 80);
            $table->timestamps();

            $table->index(['source', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_snapshots');
    }
};
