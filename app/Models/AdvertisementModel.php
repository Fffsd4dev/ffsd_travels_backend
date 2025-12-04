<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdvertisementModel extends Model
{
    use HasFactory;

    protected $table = 'advertisements'; // Specify the table name

    // If necessary, you can also define which fields are mass-assignable
    protected $fillable = [
        'title',
        'content',
        'excerpt',
        'destination',
        'fee',
        'featured_images',
        'start_date',
        'end_date',
        'category_id',
    ];

    // Define relationships (example: an advertisement belongs to a category)
    public function category()
    {
        return $this->belongsTo(AdvertCategoryModel::class); // Assuming you have an AdvertCategory model
    }
}
