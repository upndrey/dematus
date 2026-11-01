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
            $table->string('first_pick_team')->nullable()->after('dire_team');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('draft_snapshots', function (Blueprint $table): void {
            $table->dropColumn('first_pick_team');
        });
    }
};
