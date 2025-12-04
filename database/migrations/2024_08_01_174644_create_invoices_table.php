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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_sequence_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number')->unique();
            $table->foreignId('flight_order_id')->constrained()->onDelete('cascade');
            $table->string('pnr');
            $table->foreignId('company_id')->constrained('company_models')->onDelete('cascade');
            $table->date('invoice_date');
            $table->decimal('total_amount', 12, 2);
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('payment_status');
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
