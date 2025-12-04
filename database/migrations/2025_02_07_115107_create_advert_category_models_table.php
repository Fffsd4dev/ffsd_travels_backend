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
        Schema::create('advert_categories', function (Blueprint $table) { // Adjusted table name to match the model
            $table->id();
            $table->string('title',50); // 'title' field with max 255 characters
            $table->string('icon'); // 'icon' field to store file path or name
            $table->string('excerpt', 50); // 'excerpt' field with max 50 characters
            $table->text('content'); // 'content' field for longer text
            $table->timestamps(); // 'created_at' and 'updated_at' fields
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advert_categories'); // Adjusted to match the correct table name
    }
};
