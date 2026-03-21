<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('reply_to_id')->nullable()->after('content');
            $table->string('reply_to_author')->nullable()->after('reply_to_id');
            $table->string('reply_to_preview', 200)->nullable()->after('reply_to_author');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['reply_to_id', 'reply_to_author', 'reply_to_preview']);
        });
    }
};
