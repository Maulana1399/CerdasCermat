<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_states', function (Blueprint $table) {
            $table->id();
            $table->string('state', 20)->default('idle')->index();
            $table->unsignedInteger('question_number')->default(0);
            $table->foreignId('winner_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('buzz_deadline_at')->nullable();
            $table->timestamp('answer_deadline_at')->nullable();
            $table->timestamp('phase_started_at')->nullable();
            $table->foreignId('last_score_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->integer('last_score_points')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_states');
    }
};
