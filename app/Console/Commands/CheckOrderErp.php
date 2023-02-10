<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class CheckOrderErp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:check:erp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders dispatched in Opencart and send to ERP.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        sleep(2);
        #var_dump(Opencart::searchStatuses('with_error_item'));
        $orders = [205119];

        #$orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('with_error_item')]);
        
        //dd('parou no sendOrdersToErp');
        foreach ($orders as $order_id) {          
            Erp::getOrder($order_id);
        }
    }

    private function updateOpencartOrderStatus(int $order_id)
    {
        $this->log($order_id);

        Opencart::orderHistory($order_id, [
            
            'order_status_id' => Opencart::updateStatuses('in_invoicement_order'),            
            'comment' => 'Enviado ao DB intermediário. Aguardando faturamento',            
            'notify' => false,

        ]);
    }

    public function log(string $log)
    {
        var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/pedidos_check_erp.log'), $log
       );
    }


}
