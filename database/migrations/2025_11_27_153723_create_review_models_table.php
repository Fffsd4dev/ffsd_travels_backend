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
        Schema::create('review_models', function (Blueprint $table) {
            $table->id();

            // User who posted the review
            $table->string('user_name');

            // Rating 1–5
            $table->unsignedTinyInteger('rating');

            // Optional text review
            $table->text('comment')->nullable();

            // Review status
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_models');
    }
};
