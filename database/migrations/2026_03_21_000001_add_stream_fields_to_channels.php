<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the type enum to include 'stream'
        DB::statement("ALTER TABLE channels MODIFY COLUMN type ENUM('text','announcement','voice','video','stream') DEFAULT 'text'");

        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('is_live')->default(false)->after('is_private');
            $table->string('live_streamer_username')->nullable()->after('is_live');
            $table->timestamp('live_started_at')->nullable()->after('live_streamer_username');
            $table->unsignedInteger('stream_seq')->default(0)->after('live_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['is_live', 'live_streamer_username', 'live_started_at', 'stream_seq']);
        });

        DB::statement("ALTER TABLE channels MODIFY COLUMN type ENUM('text','announcement','voice','video') DEFAULT 'text'");
    }
};
