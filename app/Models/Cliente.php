<?php

namespace App\Models;

use App\Traits\NoTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'CLIENTE';

    protected $guarded = [];

    public $timestamps = false;
}
