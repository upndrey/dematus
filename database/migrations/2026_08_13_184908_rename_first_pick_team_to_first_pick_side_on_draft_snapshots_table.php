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
        Schema::table('draft_snapshots', function (Blueprint $table): void {
            $table->renameColumn('first_pick_team', 'first_pick_side');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('draft_snapshots', function (Blueprint $table): void {
            $table->renameColumn('first_pick_side', 'first_pick_team');
        });
    }
};
