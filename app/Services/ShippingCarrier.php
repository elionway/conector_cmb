<?php

namespace App\Services;

use DomainException;

final class ShippingCarrier
{
    public const RM_CARGAS = 'rm_cargas';
    public const CORREIOS = 'correios';

    public static function fromOrder(array $order): string
    {
        $code = strtolower(trim((string)($order['shipping']['code'] ?? '')));

        if (str_starts_with($code, 'ow_rmcargas.') || str_starts_with($code, 'ow_rmcargas_local.')) {
            return self::RM_CARGAS;
        }

        if (str_starts_with($code, 'ow_mscorreios.')) {
            return self::CORREIOS;
        }

        // Compatibilidade durante a publicação conjunta da API e do conector.
        $method = strtolower(trim((string)($order['shipping']['method'] ?? '')));
        if ($code === '' && $method === 'transportadora') {
            return self::RM_CARGAS;
        }

        throw new DomainException('Código de transportadora ausente ou não suportado.');
    }

    public static function isRmCargas(array $order): bool
    {
        return self::fromOrder($order) === self::RM_CARGAS;
    }

    public static function isCorreios(array $order): bool
    {
        return self::fromOrder($order) === self::CORREIOS;
    }

    public static function erpTransportCode(array $order): string
    {
        $carrier = self::fromOrder($order);
        $code = (string)config("connector.shipping.erp_transport_codes.{$carrier}", '');

        if ($code === '') {
            throw new DomainException("Código ERP não configurado para {$carrier}.");
        }

        return $code;
    }

    public static function sexpServiceId(array $order): int
    {
        $carrier = self::fromOrder($order);

        if ($carrier === self::RM_CARGAS) {
            return self::configuredSexpServiceId('rm_cargas');
        }

        $method = strtolower(trim((string)($order['shipping']['method'] ?? '')));

        if (preg_match('/\bsedex\b/u', $method)) {
            return self::configuredSexpServiceId('correios.sedex');
        }

        if (preg_match('/\bpac\b/u', $method)) {
            return self::configuredSexpServiceId('correios.pac');
        }

        throw new DomainException('Serviço dos Correios ausente ou não suportado pelo SEXP.');
    }

    public static function trackingUrl(array $order, string $trackingNumber): string
    {
        $carrier = self::fromOrder($order);
        $template = (string)config("connector.shipping.tracking_urls.{$carrier}", '');

        if ($template === '') {
            throw new DomainException("URL de rastreamento não configurada para {$carrier}.");
        }

        return sprintf($template, rawurlencode($trackingNumber));
    }

    public static function isSexpShipmentReady(array $order, string $statusCode): bool
    {
        $carrier = self::fromOrder($order);

        return ($carrier === self::RM_CARGAS && $statusCode === '5')
            || ($carrier === self::CORREIOS && $statusCode === '4');
    }

    private static function configuredSexpServiceId(string $service): int
    {
        $id = (int)config("connector.shipping.sexp_service_ids.{$service}", 0);

        if ($id <= 0) {
            throw new DomainException("ID do serviço SEXP não configurado para {$service}.");
        }

        return $id;
    }
}
