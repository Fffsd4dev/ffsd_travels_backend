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
        Schema::create('travelers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_order_id')->constrained()->onDelete('cascade');
            $table->date('date_of_birth');
            $table->string('gender');
            $table->string('am_traveler_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->json('documents');
            $table->json('contact');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travelers');
    }
};
