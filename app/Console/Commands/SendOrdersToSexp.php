<?php

namespace App\Console\Commands;

use App\Support\Opencart;
use App\Support\Sexp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class SendOrdersToSexp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:send:sexp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders paid in Opencart and send to SEXP.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        #$orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('with_error_item')]);
        $orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('new_order')]);

        foreach ($orders as $order) {
            sleep(2);

            $this->info('Enviado ao SEXP: '.$order['order_id']);

            try {
                var_dump('Encontrou order, vai altear o status '.$order['order_id']);
                Sexp::getOrder($order['order_id']);

                $this->updateOpencartOrderStatus($order['order_id']);

                $this->info("{$order['order_id']} - O pedido já existe no SEXP");
            } catch (\Throwable $th) {
                
                try {
                    var_dump('Criou order: ').$order['order_id'];
                    Sexp::createOrder($order);    
                   $this->updateOpencartOrderStatus($order['order_id']);
                } catch (\Throwable $th) {
                    var_dump('Erro na criação order: ').$order['order_id'];
                    $this->error("{$order['order_id']} - Falhou: {$th->getMessage()}");
                }
            }
        }
    }

    private function updateOpencartOrderStatus(int $order_id)
    {
        $this->log($order_id);

        Opencart::orderHistory($order_id,
         [
            'order_status_id' => Opencart::updateStatuses('integrated_sexp_order'),
            'comment' => 'Enviado para o SEXP',
            'notify' => false,
        ]);
    }

    public function log(string $log)
    {
        var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/pedidos_novos_enviados_ao_sexp.log'), $log
       );
    }

    
}
