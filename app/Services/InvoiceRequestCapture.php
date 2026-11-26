<?php

namespace App\Services;

use RuntimeException;

final class InvoiceRequestCapture
{
    public static function save(string $root, int $order, string $xml, string $url, string $contentType): string
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Diretório de capturas indisponível.');
        }
        $path = $root . '/' . $order . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
        if (!mkdir($path, 0700)) {
            throw new RuntimeException('Falha ao criar diretório privado.');
        }

        $data = json_encode([
            'pedido' => $order, 'operacao' => 'informarNF', 'preparado_utc' => gmdate('c'),
            'metodo' => 'POST', 'url' => $url, 'content_type' => $contentType,
            'bytes' => strlen($xml), 'sha256' => hash('sha256', $xml),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['envio.xml' => $xml, 'dados.json' => $data] as $name => $contents) {
            $file = $path . '/' . $name;
            if (file_put_contents($file, $contents) !== strlen($contents) || !chmod($file, 0600)) {
                throw new RuntimeException('Falha ao salvar captura antes do envio.');
            }
        }
        return $path;
    }
}
