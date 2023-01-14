<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class UpdateOrderShipment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update:shipment';

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
            $this->info('Iniciado Processo de envio do pedido: '.$order['order_id']);

            sleep(2);

            if ($invoiced = Erp::find($order['order_id'])) {
                
                $this->updateOpencartOrderStatus($order['order_id'], $invoiced['NOTA'], '989898989');

                $sexpOrder = Sexp::getOrder($order['order_id']);
                //dd($sexpOrder);
                if ($sexpOrder['status']['codigo'] == '5') {
                    var_dump('Enviou: '.$order['order_id']);
                    $this->updateOpencartOrderStatus($order['order_id'], $invoiced['NOTA'], $sexpOrder['numeroPostagem']);
                }else{
                    var_dump('Não Enviou: '.$order['order_id']);
                }
            }
        }
    }

    private function updateOpencartOrderStatus(int $orderId, string $invoiceNumber, string $trackingNumber)
    {

        $this->log($orderId);
        Opencart::orderHistory($orderId, [
            'order_status_id' => Opencart::updateStatuses('finished_order'),
            'comment' => "
                Seu <b>pedido {$orderId}</b> foi enviado. <br/> 
                Código de Rastreamento: 
                <b>Código de Rastreamento:</b> {$trackingNumber} <a href='https://cmb.pontualcargo.com.br/?codigoRastreamento={$trackingNumber}' target='_blank'>Clique aqui para acompanhamento</a>, ou utilize a URL abaixo.<br /> 
                <a href='https://cmb.pontualcargo.com.br/?codigoRastreamento={$trackingNumber}' target='_blank'>{$trackingNumber}</a>
                <b>Número da Nota Fiscal:</b> {$invoiceNumber}
            ",
            'notify' => false,
        ]);
    }

    public function log(string $log)
    {
        var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/pedidos_enviados.log'), $log
       );
    }

}
