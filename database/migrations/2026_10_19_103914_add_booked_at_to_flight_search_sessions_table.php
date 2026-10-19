
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
        Schema::table('flight_search_sessions', function (Blueprint $table) {
            $table->timestamp('booked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_search_sessions', function (Blueprint $table) {
            $table->dropColumn('booked_at');
        });
    }
};
