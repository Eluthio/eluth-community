<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_members', function (Blueprint $table) {
            $table->enum('status', ['pending', 'member', 'banned'])->default('member')->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('server_members', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
