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
        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('excerpt', 50);
            $table->string('destination');
            $table->decimal('fee', 14, 2); // Example for a price field
            $table->json('featured_images')->nullable(); // To store image paths
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('category_id')->constrained('advert_categories')->onDelete('cascade'); // Assuming categories table exists
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advertisements');
    }
};
