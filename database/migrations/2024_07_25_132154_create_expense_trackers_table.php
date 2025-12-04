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
        Schema::create('expense_trackers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_models')->onDelete('cascade'); 
            $table->foreignId('flight_offer_id')->constrained('flight_offers')->onDelete('cascade');
            $table->json('flight_details'); 
            $table->decimal('balance', 12, 2)->default(0); 
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade'); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_trackers');
    }
};