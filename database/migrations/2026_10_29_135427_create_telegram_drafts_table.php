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
        Schema::create('telegram_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('match_id');
            $table->unsignedInteger('map_number');
            $table->string('message_id')->nullable();
            $table->string('fingerprint');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status')->default('draft');
            $table->json('players');
            $table->json('source_payload');
            $table->timestamp('seen_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by')->nullable();
            $table->json('calculation')->nullable();
            $table->json('sheet_result')->nullable();
            $table->foreignId('draft_snapshot_id')->nullable()->constrained();
            $table->text('error')->nullable();
            $table->unique(['match_id', 'map_number']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_drafts');
    }
};
