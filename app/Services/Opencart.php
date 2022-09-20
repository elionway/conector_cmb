<?php

namespace App\Services;

use App\Dto\Opencart\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Opencart {
    protected string $api_url = '';

    private string $consumer_key = '';
    
    private string $consumer_secret = '';

    private string $access_token = '';

    protected int $complete_status_id = 0;

    protected array $search_statuses = [];

    protected string $cpf = '';

    protected string $cnpj = '';

    protected string $state_registration = '';

    protected string $address_number = '';

    protected string $address_complement = '';

    protected string $neighborhood = '';

    public function __construct()
    {
        $this->validateSettings();

        $this->api_url = config('connector.opencart_url');
    
        $this->consumer_key = config('connector.consumer_key');

        $this->consumer_secret = config('connector.consumer_secret');

        $this->search_statuses = config('connector.search_status');

        $this->update_statuses = config('connector.update_status');

        $this->cpf = config('connector.custom_fields')['cpf'];

        $this->cnpj = config('connector.custom_fields')['cnpj'];

        $this->state_registration = config('connector.custom_fields')['state_registration'];

        $this->address_number = config('connector.custom_fields')['address_number'];

        $this->address_complement = config('connector.custom_fields')['address_complement'];

        $this->neighborhood = config('connector.custom_fields')['neighborhood'];

        $this->accessToken();
    }

    /**
     * @param ?array $queryParams
     * @return Order[]
     */
    public function orders(?array $queryParams = [])
    {
        $response = $this->execute('order', 'get', null, $queryParams);

        return collect($response['records'] ?? []);
    }

    public function order(string|int $order_id)
    {
        $response = $this->execute("order/{$order_id}");
    }

    public function orderHistory(string|int $order_id, array $data)
    {
        $params = [
            0 => [
                'order_id' => $order_id,
                'order_status_id' => $data['order_status_id'],
                'comment' => $data['comment'],
                'notify' => $data['notify'],
            ],
        ];

        $this->execute('order/history', 'put', $params);
    }

    public function statuses(?array $queryParams = []): Collection
    {
        $response = $this->execute('order_status', 'get', null, $queryParams);

        return collect($response['records'] ?? []);
    }

    public function products(): Collection
    {
        $response = $this->execute('product');

        return collect($response['records'] ?? []);
    }

    public function searchStatuses(?string $key = ''): array | int | null
    {
        if ($key) {
            return (int) $this->search_statuses[$key] ?? null;
        }

        return $this->search_statuses;
    }

    public function updateStatuses(?string $key = ''): array | int | null
    {
        if ($key) {
            return (int) $this->update_statuses[$key] ?? null;
        }

        return $this->update_statuses;
    }

    public function cnpj(): string
    {
        return $this->cnpj;
    }

    public function cpf(): string
    {
        return $this->cpf;
    }

    public function stateRegistration(): string
    {
        return $this->state_registration;
    }

    public function addressNumber(): string
    {
        return $this->address_number;
    }

    public function addressComplement(): string
    {
        return $this->address_complement;
    }

    public function neighborhood(): string
    {
        return $this->neighborhood;
    }

    private function action(string $action, ?array $queryParams = []): string
    {
        $url = "{$this->api_url}/{$action}";

        if ($queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    private function accessToken()
    {
        $response = Http::withHeaders($this->headers())->post($this->action('access_token'));

        $this->validate($response);

        $this->access_token = $response->object()->access_token;
    }

    private function validate(Response $response)
    {
        if ($response->serverError()) {
            throw new \Exception($this->errorMessage($response));
        }
    }

    private function errorMessage(Response $response): string
    {
        $errors = $response->object()?->errors ?? [];

        $array_errors = [];

        foreach ($errors as $error) {
            $array_errors[] = $error->message;
        }

        return implode(' ', $array_errors);
    }
    
    private function headers(): array
    {
        return [
            'Authorization' => $this->basicAuth(),
        ];
    }

    private function bearer(): string
    {
        return "Bearer {$this->access_token}";
    }

    private function execute(string $action, string $method = 'get', ?array $params = [], ?array $queryParams = [])
    {
        $headers = [
            'Authorization' => $this->bearer(),
        ];

        $url = $this->action($action, $queryParams);

        $response = Http::withHeaders($headers)->$method($url, $params);

        $this->log($url, $response, $params);

        $this->validate($response);

        return $response->json();
    }

    private function log(string $url, Response $response, ?array $params = [])
    {
        $log_data = [
            'url' => $url,
            'body' => $response->json(),
            'params' => $params,
        ];

        $stringfied_data = json_encode($log_data);

        $key = md5($stringfied_data . time());

        Storage::put("api-calls/opencart/{$key}.txt", $stringfied_data);
    }

    private function basicAuth(): string
    {
        return 'Basic ' . base64_encode("{$this->consumer_key}:{$this->consumer_secret}");
    }

    private function validateSettings()
    {
        $is_settings_valid = config('connector.consumer_key') && config('connector.consumer_secret') && config('connector.opencart_url');

        throw_unless($is_settings_valid, new \Exception('As configurações estão inválidas'));
    }
}