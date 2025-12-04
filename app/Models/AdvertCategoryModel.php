<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdvertCategoryModel extends Model
{
     use HasFactory;
    // Define the table associated with the model (optional if it follows Laravel's naming conventions)
    protected $table = 'advert_categories';

    // Specify the attributes that are mass assignable
    protected $fillable = [
        'title',
        'icon',
        'excerpt',
        'content',
    ];

    // You may define any relationships if needed, for example:
    // public function advertisements()
    // {
    //     return $this->hasMany(Advertisement::class);
    // }
}
