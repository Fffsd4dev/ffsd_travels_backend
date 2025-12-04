<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogCategoryModel extends Model
{
    // Specify the table associated with the model
    protected $table = 'categories';

    // Specify which fields can be mass assigned
    protected $fillable = [
        'name',
        'description',
    ];

    // If the model has any relationships (e.g., with blogs), define them here
    public function blogs()
    {
        return $this->hasMany(Blog::class, 'category_id');
    }
}
