<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_states', function (Blueprint $table) {
            $table->boolean('throw_active')->default(false)->after('ranking_answered_team_ids');
        });
    }

    public function down(): void
    {
        Schema::table('game_states', function (Blueprint $table) {
            $table->dropColumn('throw_active');
        });
    }
};
