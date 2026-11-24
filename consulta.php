<?php

// Executar no diretório do conector que contém o arquivo artisan.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$sources = [
    'pedido' => ['dbo.VW_PEDVENDA_ECMB', 'PV_NUMPED', ['STATUS', 'DATA_IMPORTACAO', 'PV_STATUS', 'PV_NUMNF']],
    'faturados' => ['dbo.VIEW_FATURADOS', 'PEDIDO', ['NOTA', 'SERIE', 'EMISSAO']],
    'conector' => ['dbo.TBL_View_PedidosMedalhas_Faturados', 'PEDIDO', ['NOTA']],
];

$order = $argv[1] ?? '';
$source = $argv[2] ?? 'todas';
if (!preg_match('/^[0-9]{1,9}$/D', $order) || (int)$order < 1 || $argc > 3
    || ($source !== 'todas' && !isset($sources[$source]))) {
    fwrite(STDERR, "Uso: php consulta.php PEDIDO [pedido|faturados|conector|todas]\n");
    exit(2);
}

$root = getcwd();
if (!is_file("$root/artisan") || !is_file("$root/vendor/autoload.php")) {
    fwrite(STDERR, "Execute na pasta do conector que contém artisan e vendor.\n");
    exit(2);
}
require "$root/vendor/autoload.php";
$app = require "$root/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

// Os identificadores são varchar de até 10 posições e podem ter zeros à esquerda.
$digits = ltrim($order, '0');
$variants = [];
for ($width = strlen($digits); $width <= 10; $width++) {
    $variants[] = str_pad($digits, $width, '0', STR_PAD_LEFT);
}

$exitCode = 0;
foreach ($sources as $name => [$table, $key, $fields]) {
    if ($source !== 'todas' && $source !== $name) {
        continue;
    }
    echo "\n$table — pedido $digits\n";
    try {
        $rows = DB::table($table)->whereIn($key, $variants)->distinct()->get($fields);
        if ($rows->isEmpty()) {
            echo "Pedido ausente nesta fonte.\n";
        }
        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $value = $row->$field;
                $values[] = $field . '=' . ($value === null || trim((string)$value) === '' ? '(vazio)' : $value);
            }
            echo implode(' | ', $values), PHP_EOL;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "Falha ao consultar a fonte (código " . $e->getCode() . ").\n");
        $exitCode = 1;
    }
}
exit($exitCode);
