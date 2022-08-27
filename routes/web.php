<?php

use App\Support\Opencart;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    // Opencart::orderHistory(1, ['order_id' => 1, 'order_status_id' => 2, 'comment' => 'Pedido atualizado para processando', 'notify' => true]);

    Artisan::call('sync:orders');
});
