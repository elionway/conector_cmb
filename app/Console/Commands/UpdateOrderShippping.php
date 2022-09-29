<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
use Illuminate\Console\Command;

class UpdateOrderShipping extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update:shipping';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders invoiced and sent to SEXP and then update ecommerce status.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('sent_invoiced_to_sexp_order')]);

        foreach ($orders as $order) {
            if ($invoiced = Erp::find($order['order_id'])) {
                $sexpOrder = Sexp::getOrder($order['order_id']);

                if ($sexpOrder['status']['codigo'] == '5') {
                    $this->updateOpencartOrderStatus($order['order_id'], $invoiced['NOTA'], $sexpOrder['numeroPostagem']);
                }
            }
        }
    }

    private function updateOpencartOrderStatus(int $orderId, string $invoiceNumber, string $trackingNumber)
    {
        Opencart::orderHistory($orderId, [
            'order_status_id' => Opencart::updateStatuses('finished_order'),
            'comment' => "
                Seu <b>pedido {$orderId}</b> foi enviado. <br/> 
                <b>Código de Rastreamento:</b> <a href='https://cmb.pontualcargo.com.br/?codigoRastreamento={$trackingNumber}' target='_blank'>{$trackingNumber}</a> <br /> 
                <b>Número da Nota Fiscal:</b> {$invoiceNumber}
            ",
            'notify' => true,
        ]);
    }
}
