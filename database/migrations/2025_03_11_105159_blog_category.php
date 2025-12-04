<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create the categories table
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('name', 100)->unique(); // Category name (must be unique)
            $table->string('description', 255)->nullable(); // Description (nullable)
            $table->timestamps(); // Adds created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the categories table if it exists
        Schema::dropIfExists('categories');
    }
};
