<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentType extends Model
{
    use HasFactory;
    protected $fillable =['company_id','created_by_user_id','company_owner_user_id','payment_type','wallet_id','invoice_id'];
}
