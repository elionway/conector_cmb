<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasPrefix;

class Cliente extends Model
{
    use HasFactory, HasPrefix;

    protected $table = 'CLIENTE';

    private $prefix = 'CL';
}
