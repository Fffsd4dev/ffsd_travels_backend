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
        Schema::create('payments', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID
            $table->decimal('amount', 10, 2); // Amount with 10 total digits and 2 decimal places
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // Foreign key to users table
            $table->string('paid_by_email')->nullable(); // Optional email of the payer
            $table->string('payment_reference')->unique(); // Unique payment reference
            $table->string('payment_status')->default('not_confirmed'); // Default status for payments
            $table->foreignId('flight_order_id')->nullable()->constrained('flight_orders')->onDelete('set null'); // Foreign key to flight_orders table
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->onDelete('set null'); // Foreign key to users table
            $table->foreignId('paid_by_company_id')->nullable()->constrained('companies')->onDelete('set null'); // Foreign key to companies table
            $table->string('flight_pnr')->nullable(); // Flight PNR (nullable if not applicable)
            $table->string('flight_am_order_id')->nullable(); // Optional Amadeus order ID
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->onDelete('set null'); // Foreign key to wallets table
            $table->boolean('payment_confirmed')->default(false); // Indicates if payment is confirmed
            $table->timestamps(); // Created_at and updated_at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
