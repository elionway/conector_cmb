<?php

namespace App\Models;

use App\Traits\NoTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use HasFactory;

    protected $table = 'PEDVENDA';

    protected $guarded = [];

    public $timestamps = false;
}
