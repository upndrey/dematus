<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_ignored_tournaments', function (Blueprint $table): void {
            $table->id();
            $table->string('tournament_id')->unique();
            $table->string('ignored_by');
            $table->timestamps();
        });
        Schema::table('telegram_watches', function (Blueprint $table): void {
            $table->string('tournament_id')->nullable()->index();
            $table->string('tournament_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_watches', function (Blueprint $table): void {
            $table->dropIndex(['tournament_id']);
            $table->dropColumn(['tournament_id', 'tournament_name']);
        });
        Schema::dropIfExists('telegram_ignored_tournaments');
    }
};
