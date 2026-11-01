<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_watcher_states', function (Blueprint $table) {
            $table->id();
            $table->boolean('running')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
        Schema::create('telegram_ignored_matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_id')->unique();
            $table->string('ignored_by');
            $table->timestamps();
        });
        Schema::table('telegram_subscribers', function (Blueprint $table) {
            $table->timestamp('watcher_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_subscribers', function (Blueprint $table) {
            $table->dropColumn('watcher_reminded_at');
        });
        Schema::dropIfExists('telegram_ignored_matches');
        Schema::dropIfExists('telegram_watcher_states');
    }
};
