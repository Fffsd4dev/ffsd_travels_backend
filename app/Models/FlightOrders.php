<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlightOrders extends Model
{
    use HasFactory;
    protected $fillable = [
        'type',
        'queueing_office_id',
        'pnr',
    ];

    public function associatedRecords()
    {
        return $this->hasMany(AssociatedRecords::class);
    }

    public function flightOffers()
    {
        return $this->hasMany(FlightOffer::class);
    }

    public function travelers()
    {
        return $this->hasMany(Traveler::class);
    }

    public function ticketingAgreement()
    {
        return $this->hasOne(TicketingAgreement::class);
    }

    public function automatedProcesses()
    {
        return $this->hasMany(AutomatedProcess::class);
    }
}
