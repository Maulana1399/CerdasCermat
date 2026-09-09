<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('points')->default(0);
            $table->string('kind', 20)->default('custom');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_events');
    }
};
