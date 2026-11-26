<?php

namespace App\Services;

use UnexpectedValueException;

final class ShippingRecipient
{
    public static function fromShipping(array $shipping): array
    {
        $name = trim(trim((string)($shipping['firstname'] ?? '')) . ' ' . trim((string)($shipping['lastname'] ?? '')));
        if ($name === '') {
            throw new UnexpectedValueException('Nome do destinatário ausente no frete.');
        }

        $fields = array_change_key_case((array)($shipping['custom_field'] ?? []), CASE_LOWER);
        $key = strtolower(trim((string)config('connector.shipping_recipient_cpf_field', 'cpf')));
        $value = $fields[$key] ?? $shipping['cpf'] ?? '';
        $cpf = is_string($value) ? preg_replace('/[.\s-]+/', '', $value) : '';
        if (!preg_match('/^[0-9]{11}$/D', $cpf)) {
            throw new UnexpectedValueException('CPF do destinatário ausente ou inválido no frete.');
        }

        return ['cpf' => $cpf, 'nome' => $name];
    }
}
