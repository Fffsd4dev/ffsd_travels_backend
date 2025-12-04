<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyModel extends Model
{
    
    use HasFactory;
    Protected $fillable =['company_name','company_country_id','company_created_by_user_id'];

    // Define the relationship with the Staff model
    public function staff()
    {
        return $this->hasMany(Staff::class); // A company can have many staff members
    }
}
