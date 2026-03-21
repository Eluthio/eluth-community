<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('name');
            $table->enum('tier', ['official', 'approved', 'unofficial'])->default('official');
            $table->json('manifest');           // zones, description, version, etc.
            $table->string('source_url')->nullable(); // for unofficial only
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
