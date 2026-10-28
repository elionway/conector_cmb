<?php

namespace App\Services;

use Illuminate\Http\Client\Factory;
use Throwable;

/** Incident metadata only: never persist/send exception messages or order data. */
class ConnectorAlertReporter
{
    public const COMMANDS = ['orders:send:sexp', 'orders:send:erp', 'orders:update:tracking', 'orders:update:invoice', 'orders:update:shipment'];
    private ?string $command = null;
    private \WeakMap $seen;

    public function __construct(private array $options, private string $directory, private Factory $http)
    {
        $this->seen = new \WeakMap();
    }

    public static function reportSafely(Throwable $error, string $category = 'runtime'): void
    {
        try {
            if (app()->bound(self::class)) {
                app(self::class)->report($error, $category);
            }
        } catch (Throwable $ignored) {
            error_log('CMB connector alert could not be queued.');
        }
    }

    public function setCommand(?string $command): void
    {
        $this->command = in_array($command, self::COMMANDS, true) ? $command : null;
    }

    private function ready(): bool
    {
        $url = parse_url((string) ($this->options['url'] ?? ''));
        return $this->capturing()
            && is_string($this->options['secret'] ?? null) && strlen($this->options['secret']) >= 32
            && ($url['scheme'] ?? '') === 'https' && !isset($url['user']) && !isset($url['pass'])
            && !isset($url['fragment']) && !empty($url['host']);
    }

    private function capturing(): bool
    {
        return ($this->options['enabled'] ?? false) === true
            && is_string($this->options['connector'] ?? null)
            && (bool) preg_match('/^[a-z0-9_-]{1,40}$/D', $this->options['connector'])
            && in_array($this->options['environment'] ?? '', ['production', 'staging', 'local', 'testing'], true);
    }

    public function report(Throwable $error, string $category = 'runtime'): void
    {
        if (!$this->capturing() || $this->command === null || isset($this->seen[$error])) {
            return;
        }
        // Expected individual input errors are not infrastructure incidents.
        if (preg_match('/^(CEP inválido|CEP não encontrado|UF inválida)/u', $error->getMessage())) {
            return;
        }
        $this->seen[$error] = true;
        $category = in_array($category, ['runtime', 'sexp', 'erp', 'cep', 'auth', 'database'], true) ? $category : 'runtime';
        $type = (new \ReflectionClass($error))->getShortName();
        $type = in_array($type, ['Error', 'TypeError', 'ErrorException', 'RuntimeException', 'ConnectionException', 'QueryException', 'PDOException'], true) ? $type : 'Exception';
        $reason = 'unexpected_error';
        $status = 0;
        if ($error instanceof \PDOException || $type === 'QueryException') {
            $category = 'database'; $reason = 'database_error';
        } elseif ($type === 'ConnectionException' || str_starts_with($error->getMessage(), 'Falha de conexão')) {
            $reason = 'connection_error';
        }
        if (preg_match('/^Falha na autenticação da API CMB: HTTP (\d{3})\.$/D', $error->getMessage(), $match)) {
            $category = 'auth'; $reason = 'http_error'; $status = (int) $match[1];
        } elseif (str_starts_with($error->getMessage(), 'Resposta inválida na autenticação da API CMB:')) {
            $category = 'auth'; $reason = 'invalid_response';
        } elseif (preg_match('/^Falha na consulta de CEP do CMB: HTTP (\d{3})\.$/D', $error->getMessage(), $match)) {
            $category = 'cep'; $reason = 'http_error'; $status = (int) $match[1];
        }
        $key = hash('sha256', implode('|', [$this->options['connector'], $this->options['environment'], $this->command, $category, $reason, $type, $status]));
        try {
            $this->state(function (&$state) use ($key, $category, $reason, $type, $status) {
                $now = time();
                if (isset($state['pending'][$key])) {
                    $state['pending'][$key]['event']['repeats'] = min(999999, $state['pending'][$key]['event']['repeats'] + 1);
                    return;
                }
                if (count($state['pending']) >= 500) {
                    throw new \RuntimeException('Alert outbox capacity reached');
                }
                $state['pending'][$key] = ['next' => max($now, (int) ($state['cooldown'][$key] ?? 0)), 'failures' => 0, 'event' => [
                    'version' => 1, 'id' => bin2hex(random_bytes(16)), 'connector' => $this->options['connector'],
                    'environment' => $this->options['environment'], 'command' => $this->command,
                    'category' => $category, 'reason' => $reason, 'exception_type' => $type,
                    'http_status' => $status, 'occurred_at' => $now, 'repeats' => 1,
                ]];
            });
            $this->flush(1);
        } catch (Throwable $ignored) {
            error_log('CMB connector alert could not be queued.');
        }
    }

    /** Retry alerts only; never retries order processing. */
    public function flush(int $limit = 3): array
    {
        $summary = ['accepted' => 0, 'pending' => 0, 'failed' => 0];
        if (!$this->ready()) {
            if ($this->capturing()) {
                ++$summary['failed'];
                try { $this->state(function (&$state) use (&$summary) { $summary['pending'] = count($state['pending']); }); }
                catch (Throwable $ignored) { error_log('CMB connector alert configuration/storage unavailable.'); }
            }
            return $summary;
        }
        try {
            for ($attempt = 0; $attempt < max(1, min(20, $limit)); ++$attempt) {
                $job = null;
                // Claim briefly, then release the filesystem lock before HTTP.
                $this->state(function (&$state) use (&$job, &$summary) {
                    $now = time();
                    foreach ($state['pending'] as $key => $item) {
                        if ($item['next'] > $now) { continue; }
                        $lease = bin2hex(random_bytes(8));
                        $state['pending'][$key]['next'] = $now + 30;
                        $state['pending'][$key]['lease'] = $lease;
                        $job = ['key' => $key, 'event' => $item['event'], 'lease' => $lease];
                        break;
                    }
                    $state['cooldown'] = array_filter($state['cooldown'], fn ($until) => $until > $now);
                    $summary['pending'] = count($state['pending']);
                });
                if ($job === null) { break; }
                $body = json_encode($job['event'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $timestamp = (string) time();
                $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $this->options['secret']);
                try {
                    $response = $this->http->connectTimeout(2)->timeout(5)->withoutRedirecting()->withHeaders([
                        'X-CMB-Timestamp' => $timestamp, 'X-CMB-Signature' => $signature,
                        'Accept' => 'application/json',
                    ])->withBody($body, 'application/json')->post($this->options['url']);
                    $data = $response->json();
                    $ack = $response->successful() && is_array($data) && ($data['ok'] ?? false) === true
                        && ($data['id'] ?? null) === $job['event']['id']
                        && in_array($data['result'] ?? '', ['sent', 'duplicate', 'suppressed'], true);
                } catch (Throwable $ignored) {
                    $ack = false;
                }
                $this->state(function (&$state) use ($job, $ack, &$summary) {
                    $key = $job['key']; $now = time();
                    if (!isset($state['pending'][$key]) || ($state['pending'][$key]['lease'] ?? null) !== $job['lease']) { return; }
                    $item = &$state['pending'][$key];
                    if ($ack) {
                        $remaining = $item['event']['repeats'] - $job['event']['repeats'];
                        if ($remaining > 0) {
                            $item['event']['id'] = bin2hex(random_bytes(16));
                            $item['event']['repeats'] = $remaining;
                            $item['event']['occurred_at'] = $now;
                            $item['next'] = $now + 1800; $item['failures'] = 0;
                            unset($item['lease']);
                        } else { unset($state['pending'][$key]); }
                        $state['cooldown'][$key] = $now + 1800;
                        ++$summary['accepted'];
                    } else {
                        $item['failures'] = min(12, $item['failures'] + 1);
                        $item['next'] = $now + min(3600, 60 * (2 ** min(6, $item['failures'] - 1)));
                        unset($item['lease']);
                        ++$summary['failed'];
                    }
                    unset($item);
                    $summary['pending'] = count($state['pending']);
                });
            }
        } catch (Throwable $ignored) {
            ++$summary['failed'];
            error_log('CMB connector alert delivery deferred.');
        }
        return $summary;
    }

    private function state(callable $callback): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Alert storage unavailable');
        }
        $lock = fopen($this->directory . '/lock', 'c');
        if (!$lock) {
            throw new \RuntimeException('Alert storage unavailable');
        }
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new \RuntimeException('Alert storage unavailable'); }
        chmod($this->directory . '/lock', 0600);
        try {
            $path = $this->directory . '/state.json';
            $state = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : ['pending' => [], 'cooldown' => []];
            if (!is_array($state) || !isset($state['pending'], $state['cooldown']) || !is_array($state['pending']) || !is_array($state['cooldown'])) {
                throw new \RuntimeException('Invalid alert state');
            }
            $callback($state);
            $temp = tempnam($this->directory, '.state-');
            try {
                chmod($temp, 0600);
                $encoded = json_encode($state, JSON_THROW_ON_ERROR);
                if (file_put_contents($temp, $encoded) !== strlen($encoded) || !rename($temp, $path)) {
                    throw new \RuntimeException('Alert state not persisted');
                }
            } finally {
                if (is_file($temp)) { unlink($temp); }
            }
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
