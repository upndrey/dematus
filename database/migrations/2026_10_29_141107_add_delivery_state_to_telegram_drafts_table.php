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
        Schema::table('telegram_drafts', function (Blueprint $table) {
            $table->string('message_hash')->nullable();
            $table->boolean('source_changed')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_drafts', function (Blueprint $table) {
            $table->dropColumn(['message_hash', 'source_changed']);
        });
    }
};
