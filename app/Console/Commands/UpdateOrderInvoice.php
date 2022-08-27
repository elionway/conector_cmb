<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use Illuminate\Console\Command;

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
            if (Erp::orderHasInvoice($order['order_id'])) {
                $this->updateOpencartOrderStatus($order['order_id']);
            }
        }
    }

    private function updateOpencartOrderStatus(int $order_id)
    {
        Opencart::orderHistory($order_id, [
            'order_status_id' => Opencart::updateStatuses('finished_order'),
            'comment' => 'Pedido Enviado',
            'notify' => true,
        ]);
    }
}
