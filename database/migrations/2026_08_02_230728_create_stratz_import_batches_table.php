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
        Schema::create('stratz_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedSmallInteger('total_items');
            $table->unsignedSmallInteger('processed_items')->default(0);
            $table->unsignedSmallInteger('succeeded_items')->default(0);
            $table->unsignedSmallInteger('skipped_items')->default(0);
            $table->unsignedSmallInteger('failed_items')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stratz_import_batches');
    }
};
