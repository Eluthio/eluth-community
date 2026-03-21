<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_permission_overwrites', function (Blueprint $table) {
            $table->uuid('channel_id');
            $table->uuid('role_id');
            $table->boolean('can_view')->default(true);
            $table->boolean('can_send')->default(true);
            $table->primary(['channel_id', 'role_id']);
            $table->foreign('channel_id')->references('id')->on('channels')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_permission_overwrites');
    }
};
