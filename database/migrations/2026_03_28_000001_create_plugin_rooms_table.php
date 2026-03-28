<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('plugin_slug', 100);
            $table->string('channel_id');
            $table->unsignedTinyInteger('max_players')->default(2);
            $table->json('player_ids')->nullable();   // array of user IDs (null = empty slot)
            $table->json('player_names')->nullable(); // array of display names
            $table->enum('status', ['waiting', 'active', 'finished'])->default('waiting');
            $table->json('data')->nullable();         // plugin-specific state
            $table->timestamp('data_updated_at')->nullable(); // for abandoned-game detection
            $table->timestamps();
            $table->index(['plugin_slug', 'channel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_rooms');
    }
};
