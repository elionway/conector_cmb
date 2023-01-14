<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class UpdateOrderInvoice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update:invoice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders integrated on ERP in Opencart and verify if has invoice.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('invoiced_order')]);

        foreach ($orders as $order) {
            if ($invoiced = Erp::find($order['order_id'])) {
                Sexp::updateInvoicedOrder($order, $invoiced);

                $this->updateOpencartOrderStatus($order['order_id']);
            }
        }
    }

    private function updateOpencartOrderStatus(int $orderId)
    {
        $this->log($orderId);

        Opencart::orderHistory($orderId, [
            'order_status_id' => Opencart::updateStatuses('sent_invoiced_to_sexp_order'),
            'comment' => "",
            'notify' => false,
        ]);
    }

    public function log(string $log)
    {
        var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/pedidos_faturados_aguardado_no_sexp.log'), $log
       );
    }

}
