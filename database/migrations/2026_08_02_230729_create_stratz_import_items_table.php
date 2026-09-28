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
        Schema::create('stratz_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stratz_import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('match_id');
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('draft_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['stratz_import_batch_id', 'match_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stratz_import_items');
    }
};
