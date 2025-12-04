<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketAgreements extends Model
{
    use HasFactory;
    use HasFactory;

    protected $fillable = [
        'flight_order_id',
        'option',
        'delay',
    ];

    public function flightOrder()
    {
        return $this->belongsTo(FlightOrders::class);
    }

}
