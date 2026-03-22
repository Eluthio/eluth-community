<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id');
            $table->string('url', 2048);
            $table->string('title', 512)->nullable();
            $table->string('proposed_by', 64);        // username
            $table->string('proposed_by_id');          // central_user_id
            $table->timestamps();
            $table->index('channel_id');
        });

        Schema::create('watch_votes', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_id');
            $table->string('voter_id');               // central_user_id
            $table->primary(['proposal_id', 'voter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_votes');
        Schema::dropIfExists('watch_proposals');
    }
};
