<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarkUp extends Model
{
    use HasFactory;
    protected $table = 'mark_ups';
    protected $fillable =['fee_name','fee_percentage', 'created_by_user_id' ];
}
