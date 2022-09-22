<?php

namespace App\Models;

use App\Traits\NoTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faturado extends Model
{
    use HasFactory;

    protected $table = 'VIEW_FATURADOS';

    protected $guarded = [];

    public $timestamps = false;
}
