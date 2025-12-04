<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
     use HasFactory;

    protected $fillable = ['company_id', 'user_id', 'is_active', 'created_by_user_id'];

    // Define the relationship with the Company model
    public function company()
    {
        return $this->belongsTo(CompanyModel::class); // Assuming Company is the related model
    }

    // Define the relationship with the User model
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Define the relationship for the user who created the staff
    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
