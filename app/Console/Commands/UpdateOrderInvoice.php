<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
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
            if ($invoiced = Erp::find($order['order_id'])) {
                $trackingNumer = Sexp::getOrderTrackingNumber($order['order_id']);

                $this->updateOpencartOrderStatus($order['order_id'], $invoiced->nota, $trackingNumber);
            }
        }
    }

    private function updateOpencartOrderStatus(int $orderId, string $invoiceNumber, string $trackingNumber)
    {
        Opencart::orderHistory($order_id, [
            'order_status_id' => Opencart::updateStatuses('finished_order'),
            'comment' => "Seu pedido foi enviado. <br/> <b>Código de Rastreamento:</b> {$trackingNumber} <br /> <b>Número da Nota Fiscal:</b> {$invoiceNumber}",
            'notify' => true,
        ]);
    }
}
