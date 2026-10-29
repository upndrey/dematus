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
        Schema::create('telegram_watches', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('match_id');
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at');
            $table->unique(['chat_id', 'match_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_watches');
    }
};
