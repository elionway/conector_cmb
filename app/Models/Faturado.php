<?php

namespace App\Models;

use App\Traits\NoTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faturado extends Model
{
    use HasFactory;

    protected $table = 'TBL_View_PedidosMedalhas_Faturados';

    protected $guarded = [];

    public $timestamps = false;
}
