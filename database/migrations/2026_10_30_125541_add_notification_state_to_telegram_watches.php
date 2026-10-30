<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_watches', function (Blueprint $table) {
            $table->json('notification_messages')->nullable();
            $table->string('ignored_message_id')->nullable();
            $table->boolean('ignore_update_pending')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_watches', function (Blueprint $table) {
            $table->dropColumn(['notification_messages', 'ignored_message_id', 'ignore_update_pending']);
        });
    }
};
