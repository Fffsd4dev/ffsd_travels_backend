<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomatedProcesses extends Model
{
    use HasFactory;
    protected $fillable = [
        'flight_order_id',
        'code',
        'queue_number',
        'queue_category',
        'office_id',
    ];

    public function flightOrder()
    {
        return $this->belongsTo(FlightOrders::class);
    }
}
