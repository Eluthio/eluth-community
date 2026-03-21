<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed from current .env values
        DB::table('server_settings')->insert([
            ['key' => 'server_name',      'value' => env('SERVER_NAME', 'Community Server'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'join_mode',        'value' => env('SERVER_JOIN_MODE', 'open'),         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'welcome_message',  'value' => null,                                    'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('server_settings');
    }
};
