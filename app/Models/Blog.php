<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Blog extends Model
{
    // Define the table associated with the model
    protected $table = 'blogs';

    // The attributes that are mass assignable
    protected $fillable = [
        'title', 
        'slug', 
        'excerpt', 
        'post_content', 
        'featured_image',
        'category_id',
        'author_id',  
        'tags' //separated by commas like #trending, #balling etc
    ];

    // Define the relationship to the Author (assuming a User model exists for authors)
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }


}
