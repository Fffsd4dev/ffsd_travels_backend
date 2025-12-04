<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class permissionModel extends Model
{
    use HasFactory;
    protected $table ='permissions';
    Protected $fillable =['name','guard_name','created_by_user_id'];
}
