<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_emotes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();  // alphanumeric/underscore/hyphen, used as :name:
            $table->string('filename');             // stored filename (e.g. pepehappy.gif)
            $table->boolean('animated')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_emotes');
    }
};
