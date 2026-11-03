<?php

namespace App\Services;

/** Operational trace only; no payloads, exception messages or credentials. */
class ConnectorAudit
{
    private string $directory;
    private ?string $run = null;
    private ?string $command = null;
    private bool $manual = false;
    private bool $recording = false;
    public const COMMANDS = ['orders:send:sexp', 'orders:send:erp', 'orders:update:tracking', 'orders:update:invoice', 'orders:update:shipment', 'orders:check:erp'];

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('framework/connector-audit');
    }

    public function enabled(): bool
    {
        if (is_dir($this->directory) && (!is_readable($this->directory) || !is_executable($this->directory))) {
            throw new \RuntimeException('Audit storage is not accessible to this user');
        }
        clearstatcache(true, $this->directory . '/enabled');
        return is_file($this->directory . '/enabled');
    }
    public function directory(): string { return $this->directory; }
    public function setEnabled(bool $enabled): void
    {
        $this->prepare();
        if ($enabled) {
            $file = $this->directory . '/enabled';
            if (file_put_contents($file, "1\n", LOCK_EX) === false || !chmod($file, 0600)) {
                throw new \RuntimeException('Audit mode could not be enabled');
            }
        } elseif (is_file($this->directory . '/enabled') && !unlink($this->directory . '/enabled')) {
            throw new \RuntimeException('Audit mode could not be disabled');
        }
        $this->recording = true;
        $this->record($enabled ? 'mode.enabled' : 'mode.disabled');
    }

    public function begin(string $command, bool $manual): bool
    {
        $this->run = bin2hex(random_bytes(12));
        $this->command = in_array($command, self::COMMANDS, true) ? $command : 'unknown';
        $this->manual = $manual;
        $this->recording = $this->enabled();
        if ($this->enabled() && !$manual) {
            $this->record('execution.blocked');
            return false;
        }
        $this->record('execution.started');
        return true;
    }

    public function finish(int $exit): void
    {
        $this->record('execution.finished', ['exit_code' => $exit]);
        $this->manual = false; $this->recording = false; $this->run = null; $this->command = null;
    }

    public function operation(string $step, callable $callback, array $fields = [])
    {
        // Recheck at each boundary if audit was activated during a running job.
        if ($this->enabled() && !$this->manual) {
            $this->record('operation.blocked', ['operation' => $step] + $fields);
            throw new \RuntimeException('Audit mode requires explicit manual execution');
        }
        $this->record($step . '.requested', $fields);
        try {
            $result = $callback();
            $extra = is_object($result) && $result instanceof \Illuminate\Http\Client\Response
                ? ['http_status' => $result->status()] : [];
            $this->record($step . '.returned', $extra + $fields);
            return $result;
        } catch (\Throwable $error) {
            $this->record($step . '.failed', ['error' => true] + $fields);
            throw $error;
        }
    }

    public function record(string $step, array $fields = []): void
    {
        if (!$this->recording && !$this->enabled()) { return; }
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $step)) { throw new \InvalidArgumentException('Invalid audit step'); }
        $safe = [];
        foreach (['order_id','status_code','target_status','http_status','exit_code','count'] as $key) {
            if (isset($fields[$key]) && (is_int($fields[$key]) || is_string($fields[$key]))
                && preg_match('/^[0-9]{1,12}$/D', (string)$fields[$key])) { $safe[$key] = (string)$fields[$key]; }
        }
        foreach (['error','tracking_present','found','notify'] as $key) {
            if (isset($fields[$key]) && is_bool($fields[$key])) { $safe[$key] = $fields[$key]; }
        }
        if (isset($fields['operation']) && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $fields['operation'])) { $safe['operation'] = $fields['operation']; }
        $this->prepare();
        $path = $this->directory . '/audit-' . gmdate('Y-m-d') . '.jsonl';
        $handle = fopen($path, 'ab');
        if (!$handle) { throw new \RuntimeException('Audit log unavailable'); }
        try {
            if (!chmod($path, 0600) || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Audit log unavailable'); }
            if (fstat($handle)['size'] >= 50 * 1024 * 1024) { throw new \RuntimeException('Audit daily log capacity reached'); }
            $line = json_encode(['at_utc'=>gmdate('c'),'run'=>$this->run,'command'=>$this->command,
                'manual'=>$this->manual,'step'=>$step,'details'=>$safe], JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) { throw new \RuntimeException('Audit log write failed'); }
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function prepare(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Audit storage unavailable');
        }
        if (!chmod($this->directory, 0700)) { throw new \RuntimeException('Audit storage permissions unavailable'); }
    }
}
