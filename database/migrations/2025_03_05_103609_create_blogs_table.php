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
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 256); // Add title column
            $table->string('slug', 50)->unique(); // Add slug column
            $table->string('excerpt', 255); // Add excerpt column
            $table->text('post_content'); // Add content column
            $table->string('featured_image')->nullable(); // Add image path column
            $table->unsignedBigInteger('author_id'); // Add author_id column
            $table->unsignedBigInteger('category_id'); // Add category_id column
            $table->string('tags')->nullable(); // Add tags column
            $table->timestamps(); // Add created_at and updated_at columns

            // Foreign key constraint for author_id
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');

            // Foreign key constraint for category_id
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
