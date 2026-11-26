<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
use App\Services\ShippingCarrier;
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
    protected $signature = 'orders:send:sexp {--audit-manual : Explicit manual execution during audit mode}';

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
        if (!app(\App\Services\ConnectorAudit::class)->begin('orders:send:sexp', (bool)$this->option('audit-manual'))) {
            $this->error('Auditoria ativa: execute manualmente com --audit-manual.');
            return 1;
        }
        $orders = $this->nextRmCargasOrders();

        foreach ($orders as $order) {
            try {
                $response = Sexp::getOrder($order['order_id']);
                if (empty($response['error'])) {
                    continue;
                }
                if (!Sexp::isMissingOrderResponse($response)) {
                    throw new \RuntimeException('Falha na consulta da remessa legada.');
                }
                $invoice = Erp::find($order['order_id']);
                if ($invoice) {
                    Sexp::processInvoicedOrder($order, $invoice);
                }
            } catch (\UnexpectedValueException $error) {
                $this->warn("Pedido {$order['order_id']}: " . $error->getMessage());
            } catch (\Throwable $error) {
                \App\Services\ConnectorAlertReporter::reportSafely($error, 'sexp');
                $this->error("Pedido {$order['order_id']}: falha na consulta ou integração.");
            }
        }
    }

    private function nextRmCargasOrders(): array
    {
        // Somente remessas RM já iniciadas pelo fluxo anterior.
        $status = Opencart::searchStatuses('in_dispatchment_order');
        $orders = [];
        foreach (Opencart::orders(['filter_status' => $status]) as $key => $order) {
            try {
                if (ShippingCarrier::isRmCargas($order)) {
                    $orders[$key] = $order;
                }
            } catch (\Throwable $th) {
                \App\Services\ConnectorAlertReporter::reportSafely($th, 'runtime');
                $this->error('Pedido sem transportadora suportada: ' . ($order['order_id'] ?? 'desconhecido'));
            }
        }

        return $orders;
    }

}
