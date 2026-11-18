<?php

namespace App\Console\Commands;

use App\Models\Faturado;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class InspectErp extends Command
{
    protected $signature = 'erp:inspect
        {object=TBL_View_PedidosMedalhas_Faturados : Nome da tabela ou view; aceite schema.objeto}
        {--list : Lista as tabelas e views visíveis para a conexão do ERP}
        {--order= : Exibe a linha de faturamento de um pedido}';

    protected $description = 'Consulta, sem gravar dados, objetos, colunas e uma linha de faturamento do ERP.';

    public function handle(): int
    {
        try {
            if ($this->option('list')) {
                return $this->showObjects();
            }

            $orderId = trim((string)$this->option('order'));
            if ($orderId !== '') {
                return $this->showInvoice($orderId);
            }

            return $this->showColumns((string)$this->argument('object'));
        } catch (Throwable $error) {
            $this->error('Falha na consulta somente leitura do ERP: ' . $error->getMessage());

            return self::FAILURE;
        }
    }

    private function showObjects(): int
    {
        $objects = DB::select(
            'SELECT TABLE_SCHEMA AS table_schema,
                    TABLE_NAME AS table_name,
                    TABLE_TYPE AS table_type
             FROM INFORMATION_SCHEMA.TABLES
             ORDER BY TABLE_SCHEMA, TABLE_NAME'
        );

        $this->info('Tabelas e views visíveis para a conexão do ERP:');
        $this->table(
            ['Schema', 'Objeto', 'Tipo'],
            array_map(function ($object): array {
                $data = array_change_key_case((array)$object, CASE_LOWER);

                return [
                    $data['table_schema'] ?? '',
                    $data['table_name'] ?? '',
                    $data['table_type'] ?? '',
                ];
            }, $objects)
        );

        $this->line('Total: ' . count($objects));

        return self::SUCCESS;
    }

    private function showColumns(string $object): int
    {
        [$schema, $table] = $this->parseObject($object);
        $sql = 'SELECT TABLE_SCHEMA AS table_schema,
                       TABLE_NAME AS table_name,
                       COLUMN_NAME AS column_name,
                       DATA_TYPE AS data_type,
                       CHARACTER_MAXIMUM_LENGTH AS maximum_length,
                       IS_NULLABLE AS is_nullable,
                       ORDINAL_POSITION AS ordinal_position
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = ?';
        $bindings = [$table];

        if ($schema !== null) {
            $sql .= ' AND TABLE_SCHEMA = ?';
            $bindings[] = $schema;
        }

        $sql .= ' ORDER BY TABLE_SCHEMA, ORDINAL_POSITION';
        $columns = DB::select($sql, $bindings);

        if (!$columns) {
            $this->warn("Nenhuma coluna visível para {$object}.");

            return self::FAILURE;
        }

        $this->info("Colunas visíveis de {$object}:");
        $this->table(
            ['#', 'Schema', 'Coluna', 'Tipo', 'Tamanho', 'Aceita NULL'],
            array_map(function ($column): array {
                $data = array_change_key_case((array)$column, CASE_LOWER);

                return [
                    $data['ordinal_position'] ?? '',
                    $data['table_schema'] ?? '',
                    $data['column_name'] ?? '',
                    $data['data_type'] ?? '',
                    $data['maximum_length'] ?? '-',
                    $data['is_nullable'] ?? '',
                ];
            }, $columns)
        );

        $this->line('Total: ' . count($columns));

        return self::SUCCESS;
    }

    private function showInvoice(string $orderId): int
    {
        if (!preg_match('/^[0-9]{1,9}$/D', $orderId)) {
            $this->error('O número do pedido deve conter de 1 a 9 dígitos.');

            return self::FAILURE;
        }

        $paddedOrderId = str_pad($orderId, 9, '0', STR_PAD_LEFT);
        $invoice = Faturado::where('PEDIDO', $paddedOrderId)->first();

        if (!$invoice) {
            $this->warn("Nenhuma linha de faturamento encontrada para o pedido {$paddedOrderId}.");

            return self::FAILURE;
        }

        $this->info("Faturamento encontrado para o pedido {$paddedOrderId}:");
        $this->table(
            ['Campo', 'Valor'],
            array_map(
                fn (string $field, $value): array => [$field, $this->formatValue($value)],
                array_keys($invoice->getAttributes()),
                array_values($invoice->getAttributes())
            )
        );

        return self::SUCCESS;
    }

    private function parseObject(string $object): array
    {
        $object = trim($object);

        if (!preg_match('/^(?:(?<schema>[A-Za-z0-9_]+)\.)?(?<table>[A-Za-z0-9_]+)$/D', $object, $matches)) {
            throw new \InvalidArgumentException('Nome de tabela/view inválido. Use objeto ou schema.objeto.');
        }

        return [($matches['schema'] ?? '') !== '' ? $matches['schema'] : null, $matches['table']];
    }

    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
