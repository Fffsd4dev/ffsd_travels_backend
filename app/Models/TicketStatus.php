<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketStatus extends Model
{
    use HasFactory;

    // Define the table name
    protected $table = 'ticket_statuses';

    // Specify the fillable fields
    protected $fillable = [
        'flight_order_id',
        'pnr',
        'ticket_status'
    ];

    protected $hidden = ['created_at', 'updated_at'];

    /**
     * Define the relationship to the FlightOrders model
     */
    public function flightOrder()
    {
        return $this->belongsTo(FlightOrders::class, 'flight_order_id');
    }

    /**
     * Define the relationship to the AssociatedRecords model
     */
    public function associatedRecords()
    {
        return $this->hasMany(AssociatedRecords::class, 'flight_order_id', 'flight_order_id');
    }

    /**
     * Define the relationship to the Travelers model
     */
    public function travelers()
    {
        return $this->hasMany(Travelers::class, 'flight_order_id', 'flight_order_id');
    }

    /**
     * Define the relationship to the FlightOffers model
     */
    public function flightOffer()
    {
        return $this->hasOne(FlightOffers::class, 'flight_order_id', 'flight_order_id');
    }
}
