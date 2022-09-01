<?php

namespace App\Models;

use App\Traits\NoTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Titulo extends Model
{
    use HasFactory;

    protected $table = 'TITULO';

    protected $guarded = [];

    public $timestamps = false;
}
