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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_models')->onDelete('cascade'); // Assuming this references a companies table
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade'); // Assuming this references a users table
            $table->decimal('total_deposit', 12, 2)->default(0);
            $table->decimal('total_spent', 12, 2)->default(0); 
            $table->decimal('balance', 12, 2)->default(0); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
