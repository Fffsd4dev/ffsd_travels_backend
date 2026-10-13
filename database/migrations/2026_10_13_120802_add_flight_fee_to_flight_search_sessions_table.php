<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_search_sessions', function (Blueprint $table) {
            $table->decimal('flight_fee', 12, 2)->nullable();
            $table->string('flight_currency', 3)->nullable();
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->decimal('total_payable', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flight_search_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'flight_fee',
                'flight_currency',
                'service_fee',
                'total_payable',
            ]);
        });
    }
};