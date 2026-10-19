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
        Schema::table('flight_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('flight_search_session_id')
                ->nullable()
                ->after('queueing_office_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_orders', function (Blueprint $table) {
            $table->dropColumn('flight_search_session_id');
        });
    }
};
