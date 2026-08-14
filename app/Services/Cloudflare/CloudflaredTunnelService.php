<?php

namespace App\Services\Cloudflare;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class CloudflaredTunnelService
{
    private const NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/';

    private const HOSTNAME_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/';

    public function __construct(
        private readonly ?CloudflareDnsService $dnsService = null,
    ) {}

    /**
     * @return array{
     *     binary: bool,
     *     binary_path: string|null,
     *     logged_in: bool,
     *     name: string|null,
     *     hostname: string|null,
     *     running: bool,
     *     pid: int|null,
     *     message: string|null,
     *     available: bool
     * }
     */
    public function status(?string $preferredName = null): array
    {
        $binaryPath = $this->resolveBinaryPath(downloadIfMissing: false);
        $hasBinary = $binaryPath !== null && is_executable($binaryPath);
        $loggedIn = is_file($this->certPath());
        $config = $this->readConfig();
        $name = $preferredName ?: ($config['name'] ?? null) ?: ($config['tunnel'] ?? null);
        if (is_string($name) && preg_match('/^[0-9a-f-]{36}$/i', $name)) {
            $name = $this->resolveTunnelNameById($name) ?? $name;
        }
        $hostname = $this->primaryHostnameFromConfig($config);
        $pid = $this->readPid();
        $running = $pid !== null && $this->isProcessRunning($pid);

        $message = null;
        if (! $hasBinary) {
            $message = 'cloudflared binary was not found. It will be downloaded on first use when running under Sail.';
        } elseif (! $loggedIn) {
            $message = 'Not logged in. Use Create tunnel to authenticate with Cloudflare.';
        } elseif (! is_dir($this->configDir())) {
            $message = 'Cloudflared config directory is missing. Ensure ~/.cloudflared is mounted into Sail.';
        }

        return [
            'binary' => $hasBinary,
            'binary_path' => $hasBinary ? $binaryPath : null,
            'logged_in' => $loggedIn,
            'name' => is_string($name) && $name !== '' ? $name : null,
            'hostname' => $hostname,
            'running' => $running,
            'pid' => $running ? $pid : null,
            'message' => $message,
            'available' => $hasBinary && is_dir($this->configDir()),
        ];
    }

    /**
     * @return array{ok: bool, message: string, login_url?: string|null}
     */
    public function startLogin(): array
    {
        if (is_file($this->certPath())) {
            return ['ok' => true, 'message' => 'Already logged in.', 'login_url' => null];
        }

        $this->ensureConfigDir();
        $binary = $this->requireBinary();

        $logPath = $this->runtimePath('login.log');
        @unlink($logPath);

        $command = sprintf(
            'nohup %s tunnel login > %s 2>&1 & echo $!',
            escapeshellarg($binary),
            escapeshellarg($logPath),
        );

        $result = Process::path($this->configDir())
            ->timeout(10)
            ->env($this->processEnv())
            ->run(['bash', '-c', $command]);

        if (! $result->successful()) {
            return [
                'ok' => false,
                'message' => 'Failed to start cloudflared login: '.$result->errorOutput(),
            ];
        }

        $pid = (int) trim($result->output());
        if ($pid > 0) {
            file_put_contents($this->runtimePath('login.pid'), (string) $pid);
        }

        // Give cloudflared a moment to print the URL.
        usleep(800_000);
        $loginUrl = $this->extractLoginUrlFromLog($logPath);

        return [
            'ok' => true,
            'message' => $loginUrl
                ? 'Open the Cloudflare login URL and authorize a zone.'
                : 'Login started. Waiting for Cloudflare authorization URL…',
            'login_url' => $loginUrl,
        ];
    }

    /**
     * @return array{ok: bool, logged_in: bool, login_url: string|null, message: string}
     */
    public function loginStatus(): array
    {
        $logPath = $this->runtimePath('login.log');
        $loginUrl = is_file($logPath) ? $this->extractLoginUrlFromLog($logPath) : null;
        $loggedIn = is_file($this->certPath());

        if ($loggedIn) {
            $this->stopLoginProcess();

            return [
                'ok' => true,
                'logged_in' => true,
                'login_url' => $loginUrl,
                'message' => 'Logged in successfully.',
            ];
        }

        return [
            'ok' => true,
            'logged_in' => false,
            'login_url' => $loginUrl,
            'message' => $loginUrl
                ? 'Waiting for browser authorization…'
                : 'Waiting for cloudflared to produce a login URL…',
        ];
    }

    /**
     * @return array{ok: bool, message: string, tunnel_id?: string|null, name?: string}
     */
    public function createTunnel(string $name): array
    {
        $this->assertValidName($name);
        $this->ensureConfigDir();
        $this->requireBinary();

        if (! is_file($this->certPath())) {
            return ['ok' => false, 'message' => 'Not logged in. Complete Cloudflare login first.'];
        }

        $existing = $this->findTunnelByName($name);
        if ($existing !== null) {
            return [
                'ok' => true,
                'message' => "Tunnel {$name} already exists.",
                'tunnel_id' => $existing['id'] ?? null,
                'name' => $name,
            ];
        }

        $result = $this->runCloudflared([
            'tunnel', 'create', $name,
        ], timeout: 60);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        $created = $this->findTunnelByName($name);
        $tunnelId = $created['id'] ?? $this->extractUuid($result['output'] ?? '');

        return [
            'ok' => true,
            'message' => "Tunnel {$name} created.",
            'tunnel_id' => $tunnelId,
            'name' => $name,
        ];
    }

    /**
     * @return array{ok: bool, message: string, record_name?: string, zone?: string, manual?: bool, expected_cname?: string}
     */
    public function routeDns(string $name, string $hostname): array
    {
        $this->assertValidName($name);
        $this->assertValidHostname($hostname);
        $this->ensureConfigDir();

        $tunnel = $this->findTunnelByName($name);
        if ($tunnel === null || empty($tunnel['id'])) {
            return ['ok' => false, 'message' => "Tunnel {$name} was not found."];
        }

        $tunnelId = (string) $tunnel['id'];
        $expectedTarget = $tunnelId.'.cfargotunnel.com';

        $dns = $this->dnsService ?? app(CloudflareDnsService::class);
        $apiToken = (string) config('services.cloudflare.api_token', '');
        if ($apiToken !== '') {
            $apiResult = $dns->upsertTunnelCname($hostname, $tunnelId);
            if ($apiResult['ok']) {
                return $apiResult;
            }

            // Fall through to CLI if API fails for a soft reason; still surface API message.
            $apiMessage = $apiResult['message'];
        } else {
            $apiMessage = null;
        }

        $result = $this->runCloudflared([
            'tunnel', 'route', 'dns', $name, $hostname,
        ], timeout: 60);

        $output = ($result['output'] ?? '')."\n".($result['error'] ?? '');
        $nestedHint = $this->detectNestedDnsRecord($hostname, $output);

        if ($nestedHint !== null) {
            return [
                'ok' => false,
                'manual' => true,
                'message' => 'cloudflared created the CNAME in the wrong zone (login cert is scoped to another domain). '
                    ."Add a proxied CNAME manually: Name = {$hostname}, Target = {$expectedTarget}."
                    .($apiMessage ? " API attempt: {$apiMessage}" : ''),
                'expected_cname' => $expectedTarget,
                'record_name' => $nestedHint,
            ];
        }

        if (! $result['ok']) {
            // "already exists" is often fine if the record is correct.
            if (Str::contains(strtolower($output), ['already exists', 'cname already'])) {
                return [
                    'ok' => true,
                    'message' => "DNS route for {$hostname} already exists.",
                    'record_name' => $hostname,
                ];
            }

            return [
                'ok' => false,
                'manual' => true,
                'message' => ($apiMessage ? "{$apiMessage} " : '')
                    .'CLI route dns failed: '.($result['message'] ?: 'unknown error')
                    .". Add a proxied CNAME manually: {$hostname} → {$expectedTarget}.",
                'expected_cname' => $expectedTarget,
            ];
        }

        if (preg_match('/Added CNAME\s+(\S+)/i', $output, $matches) === 1) {
            $createdName = rtrim($matches[1], '.');
            if (strcasecmp($createdName, $hostname) !== 0 && ! Str::endsWith(strtolower($createdName), '.'.strtolower($hostname))) {
                // Created something other than the requested hostname (often hostname.loginzone).
                if (strcasecmp($createdName, $hostname) !== 0) {
                    return [
                        'ok' => false,
                        'manual' => true,
                        'message' => "cloudflared reported CNAME {$createdName} instead of {$hostname}. "
                            ."Add a proxied CNAME manually in the correct zone: {$hostname} → {$expectedTarget}.",
                        'record_name' => $createdName,
                        'expected_cname' => $expectedTarget,
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'message' => "DNS CNAME for {$hostname} routed to tunnel {$name}.",
            'record_name' => $hostname,
        ];
    }

    /**
     * Write/merge config.yml and start the detached tunnel process.
     *
     * @return array{ok: bool, message: string, status?: array<string, mixed>}
     */
    public function runTunnel(string $name, string $hostname, bool $updateAppUrl = true): array
    {
        $this->assertValidName($name);
        $this->assertValidHostname($hostname);
        $this->ensureConfigDir();
        $this->requireBinary();

        $tunnel = $this->findTunnelByName($name);
        if ($tunnel === null || empty($tunnel['id'])) {
            return ['ok' => false, 'message' => "Tunnel {$name} was not found."];
        }

        $tunnelId = (string) $tunnel['id'];
        $credentialsFile = $this->configDir().DIRECTORY_SEPARATOR.$tunnelId.'.json';
        if (! is_file($credentialsFile)) {
            return [
                'ok' => false,
                'message' => "Credentials file missing: {$credentialsFile}. Re-create the tunnel or copy credentials into the mounted ~/.cloudflared directory.",
            ];
        }

        $this->writeConfig($tunnelId, $credentialsFile, $hostname, $name);

        $start = $this->start($name);
        if (! $start['ok']) {
            return $start;
        }

        if ($updateAppUrl) {
            $this->updateAppUrl('https://'.$hostname);
        }

        return [
            'ok' => true,
            'message' => $updateAppUrl
                ? "Tunnel {$name} started and APP_URL set to https://{$hostname}."
                : "Tunnel {$name} started.",
            'status' => $this->status($name),
        ];
    }

    /**
     * Start an existing named tunnel using current config.yml (or name from request).
     *
     * @return array{ok: bool, message: string, status?: array<string, mixed>}
     */
    public function start(string $name): array
    {
        $this->assertValidName($name);
        $this->ensureConfigDir();
        $binary = $this->requireBinary();

        if (! is_file($this->certPath()) && ! is_file($this->configPath())) {
            return ['ok' => false, 'message' => 'Tunnel is not configured. Use Create tunnel first.'];
        }

        $existingPid = $this->readPid();
        if ($existingPid !== null && $this->isProcessRunning($existingPid)) {
            return [
                'ok' => true,
                'message' => "Tunnel already running (pid {$existingPid}).",
                'status' => $this->status($name),
            ];
        }

        $this->ensureRuntimeDir();
        $logPath = $this->runtimePath('tunnel.log');
        $pidPath = $this->runtimePath('tunnel.pid');
        $configPath = $this->configPath();

        $args = ['tunnel'];
        if (is_file($configPath)) {
            $args[] = '--config';
            $args[] = $configPath;
        }
        $args[] = 'run';
        $args[] = $name;

        $escaped = array_map('escapeshellarg', [$binary, ...$args]);
        $command = sprintf(
            'nohup %s > %s 2>&1 & echo $!',
            implode(' ', $escaped),
            escapeshellarg($logPath),
        );

        $result = Process::timeout(10)
            ->env($this->processEnv())
            ->run(['bash', '-c', $command]);

        if (! $result->successful()) {
            return [
                'ok' => false,
                'message' => 'Failed to start cloudflared: '.$result->errorOutput(),
            ];
        }

        $pid = (int) trim($result->output());
        if ($pid <= 0) {
            return ['ok' => false, 'message' => 'cloudflared started but PID could not be determined.'];
        }

        file_put_contents($pidPath, (string) $pid);
        usleep(400_000);

        if (! $this->isProcessRunning($pid)) {
            $logTail = is_file($logPath) ? trim((string) file_get_contents($logPath)) : '';

            return [
                'ok' => false,
                'message' => 'cloudflared exited immediately.'
                    .($logTail !== '' ? ' Log: '.Str::limit($logTail, 500) : ''),
            ];
        }

        return [
            'ok' => true,
            'message' => "Tunnel {$name} started (pid {$pid}).",
            'status' => $this->status($name),
        ];
    }

    /**
     * @return array{ok: bool, message: string, status?: array<string, mixed>}
     */
    public function stop(): array
    {
        $pid = $this->readPid();
        if ($pid === null) {
            return [
                'ok' => true,
                'message' => 'Tunnel is not running.',
                'status' => $this->status(),
            ];
        }

        if ($this->isProcessRunning($pid)) {
            posix_kill($pid, SIGTERM);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && $this->isProcessRunning($pid)) {
                usleep(100_000);
            }
            if ($this->isProcessRunning($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        @unlink($this->runtimePath('tunnel.pid'));

        return [
            'ok' => true,
            'message' => "Stopped tunnel process {$pid}.",
            'status' => $this->status(),
        ];
    }

    public function updateAppUrl(string $url): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_writable($envPath)) {
            throw new RuntimeException('.env is not writable.');
        }

        $contents = (string) file_get_contents($envPath);
        if (preg_match('/^APP_URL=.*$/m', $contents) === 1) {
            $contents = preg_replace('/^APP_URL=.*$/m', 'APP_URL='.$url, $contents, 1) ?? $contents;
        } else {
            $contents = rtrim($contents)."\nAPP_URL={$url}\n";
        }

        file_put_contents($envPath, $contents);
        Artisan::call('config:clear');
    }

    public function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) > 64) {
            throw new RuntimeException('Invalid tunnel name. Use letters, numbers, and hyphens (max 64).');
        }
    }

    public function assertValidHostname(string $hostname): void
    {
        $hostname = strtolower(trim($hostname));
        if (preg_match(self::HOSTNAME_PATTERN, $hostname) !== 1 || strlen($hostname) > 253) {
            throw new RuntimeException('Invalid hostname.');
        }
    }

    public function configDir(): string
    {
        $configured = (string) env('CLOUDFLARED_CONFIG_DIR', '');
        if ($configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        $home = (string) (getenv('HOME') ?: '/home/sail');

        return $home.DIRECTORY_SEPARATOR.'.cloudflared';
    }

    public function configPath(): string
    {
        return $this->configDir().DIRECTORY_SEPARATOR.'config.yml';
    }

    public function certPath(): string
    {
        return $this->configDir().DIRECTORY_SEPARATOR.'cert.pem';
    }

    public function runtimeDir(): string
    {
        return storage_path('app/cloudflared');
    }

    public function runtimePath(string $file): string
    {
        return $this->runtimeDir().DIRECTORY_SEPARATOR.$file;
    }

    /**
     * @return array<string, mixed>
     */
    public function readConfig(): array
    {
        $path = $this->configPath();
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $config = [
            'tunnel' => null,
            'credentials-file' => null,
            'name' => null,
            'ingress' => [],
        ];
        $inIngress = false;
        $currentHost = null;
        $currentService = null;

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if ($line === '') {
                continue;
            }

            // Tunnel display name is stored as a YAML comment (not a cloudflared key).
            if (preg_match('/^#\s*name:\s*(.+)$/', ltrim($line), $m)) {
                $config['name'] = trim($m[1], " \t\"'");

                continue;
            }

            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^tunnel:\s*(.+)$/', $line, $m)) {
                $config['tunnel'] = trim($m[1], " \t\"'");
                $inIngress = false;

                continue;
            }
            if (preg_match('/^credentials-file:\s*(.+)$/', $line, $m)) {
                $config['credentials-file'] = trim($m[1], " \t\"'");
                $inIngress = false;

                continue;
            }
            if (preg_match('/^ingress:\s*$/', $line)) {
                $inIngress = true;

                continue;
            }
            if (! $inIngress) {
                continue;
            }
            if (preg_match('/^\s*-\s*hostname:\s*(.+)$/', $line, $m)) {
                if ($currentHost !== null && $currentService !== null) {
                    $config['ingress'][] = ['hostname' => $currentHost, 'service' => $currentService];
                }
                $currentHost = trim($m[1], " \t\"'");
                $currentService = null;

                continue;
            }
            if (preg_match('/^\s*service:\s*(.+)$/', $line, $m)) {
                $service = trim($m[1], " \t\"'");
                if ($currentHost !== null) {
                    $currentService = $service;
                } else {
                    $config['ingress'][] = ['service' => $service];
                }

                continue;
            }
            if (preg_match('/^\s*-\s*service:\s*(.+)$/', $line, $m)) {
                if ($currentHost !== null && $currentService !== null) {
                    $config['ingress'][] = ['hostname' => $currentHost, 'service' => $currentService];
                    $currentHost = null;
                    $currentService = null;
                }
                $config['ingress'][] = ['service' => trim($m[1], " \t\"'")];
            }
        }

        if ($currentHost !== null && $currentService !== null) {
            $config['ingress'][] = ['hostname' => $currentHost, 'service' => $currentService];
        }

        return $config;
    }

    public function writeConfig(string $tunnelId, string $credentialsFile, string $hostname, string $name): void
    {
        $existing = $this->readConfig();
        $ingress = [];
        $seenHostname = false;

        foreach ($existing['ingress'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (isset($rule['hostname'])) {
                if (strcasecmp((string) $rule['hostname'], $hostname) === 0) {
                    $ingress[] = [
                        'hostname' => $hostname,
                        'service' => 'http://127.0.0.1:80',
                    ];
                    $seenHostname = true;
                } else {
                    $ingress[] = [
                        'hostname' => (string) $rule['hostname'],
                        'service' => (string) ($rule['service'] ?? 'http://127.0.0.1:80'),
                    ];
                }
            }
        }

        if (! $seenHostname) {
            $ingress[] = [
                'hostname' => $hostname,
                'service' => 'http://127.0.0.1:80',
            ];
        }

        $ingress[] = ['service' => 'http_status:404'];

        $yaml = "# name: {$name}\n";
        $yaml .= "tunnel: {$tunnelId}\n";
        $yaml .= 'credentials-file: '.$credentialsFile."\n\n";
        $yaml .= "ingress:\n";
        foreach ($ingress as $rule) {
            if (isset($rule['hostname'])) {
                $yaml .= '  - hostname: '.$rule['hostname']."\n";
                $yaml .= '    service: '.$rule['service']."\n";
            } else {
                $yaml .= '  - service: '.$rule['service']."\n";
            }
        }

        file_put_contents($this->configPath(), $yaml);
    }

    /**
     * @return list<array{id?: string, name?: string}>
     */
    public function listTunnels(): array
    {
        $result = $this->runCloudflared(['tunnel', 'list', '--output', 'json'], timeout: 30);
        if (! $result['ok']) {
            return [];
        }

        $decoded = json_decode($result['output'] ?? '', true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @return array{id?: string, name?: string}|null
     */
    public function findTunnelByName(string $name): ?array
    {
        foreach ($this->listTunnels() as $tunnel) {
            if (strcasecmp((string) ($tunnel['name'] ?? ''), $name) === 0) {
                return $tunnel;
            }
        }

        return null;
    }

    protected function resolveTunnelNameById(string $id): ?string
    {
        foreach ($this->listTunnels() as $tunnel) {
            if (strcasecmp((string) ($tunnel['id'] ?? ''), $id) === 0) {
                return isset($tunnel['name']) ? (string) $tunnel['name'] : null;
            }
        }

        return null;
    }

    protected function primaryHostnameFromConfig(array $config): ?string
    {
        foreach ($config['ingress'] ?? [] as $rule) {
            if (is_array($rule) && ! empty($rule['hostname'])) {
                return (string) $rule['hostname'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $args
     * @return array{ok: bool, message: string, output?: string, error?: string}
     */
    protected function runCloudflared(array $args, int $timeout = 30): array
    {
        $binary = $this->requireBinary();
        /** @var PendingProcess $pending */
        $pending = Process::timeout($timeout)->env($this->processEnv());
        $result = $pending->run([$binary, ...$args]);

        if (! $result->successful()) {
            return [
                'ok' => false,
                'message' => trim($result->errorOutput() ?: $result->output()) ?: 'cloudflared command failed.',
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ];
        }

        return [
            'ok' => true,
            'message' => 'ok',
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ];
    }

    protected function requireBinary(): string
    {
        $path = $this->resolveBinaryPath(downloadIfMissing: true);
        if ($path === null || ! is_executable($path)) {
            throw new RuntimeException('cloudflared binary is not available.');
        }

        return $path;
    }

    protected function resolveBinaryPath(bool $downloadIfMissing): ?string
    {
        $configured = (string) env('CLOUDFLARED_BIN', '');
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $which = Process::timeout(5)->run(['bash', '-c', 'command -v cloudflared']);
        if ($which->successful()) {
            $path = trim($which->output());
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        $stored = $this->runtimePath('cloudflared');
        if (is_executable($stored)) {
            return $stored;
        }

        if ($downloadIfMissing && $this->shouldDownloadBinary()) {
            $this->downloadBinary($stored);
            if (is_executable($stored)) {
                return $stored;
            }
        }

        return null;
    }

    protected function shouldDownloadBinary(): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        return env('LARAVEL_SAIL') === '1'
            || PHP_OS_FAMILY === 'Linux';
    }

    protected function downloadBinary(string $dest): void
    {
        $this->ensureRuntimeDir();
        $url = 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64';
        $response = Http::timeout(120)->withOptions(['sink' => $dest])->get($url);
        if (! $response->successful() || ! is_file($dest)) {
            throw new RuntimeException('Failed to download cloudflared binary.');
        }
        chmod($dest, 0755);
    }

    /**
     * @return array<string, string>
     */
    protected function processEnv(): array
    {
        $env = [
            'HOME' => (string) (getenv('HOME') ?: '/home/sail'),
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'TUNNEL_ORIGIN_CERT' => $this->certPath(),
        ];

        return $env;
    }

    protected function ensureConfigDir(): void
    {
        $dir = $this->configDir();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create cloudflared config directory: {$dir}");
        }
    }

    protected function ensureRuntimeDir(): void
    {
        $dir = $this->runtimeDir();
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create runtime directory: {$dir}");
        }
    }

    protected function readPid(): ?int
    {
        $path = $this->runtimePath('tunnel.pid');
        if (! is_file($path)) {
            return null;
        }
        $pid = (int) trim((string) file_get_contents($path));

        return $pid > 0 ? $pid : null;
    }

    protected function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    protected function stopLoginProcess(): void
    {
        $path = $this->runtimePath('login.pid');
        if (! is_file($path)) {
            return;
        }
        $pid = (int) trim((string) file_get_contents($path));
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            posix_kill($pid, SIGTERM);
        }
        @unlink($path);
    }

    protected function extractLoginUrlFromLog(string $logPath): ?string
    {
        if (! is_file($logPath)) {
            return null;
        }
        $contents = (string) file_get_contents($logPath);
        if (preg_match('#https://[^\s]+cloudflare[^\s]*#i', $contents, $matches) === 1) {
            return rtrim($matches[0], '.,)');
        }

        return null;
    }

    protected function extractUuid(string $text): ?string
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    protected function detectNestedDnsRecord(string $requestedHostname, string $output): ?string
    {
        if (preg_match('/Added CNAME\s+(\S+)/i', $output, $matches) !== 1) {
            return null;
        }
        $created = rtrim($matches[1], '.');
        $requested = strtolower($requestedHostname);
        $createdLower = strtolower($created);
        if ($createdLower === $requested) {
            return null;
        }
        // e.g. name.domain2.com.domain1.com
        if (Str::startsWith($createdLower, $requested.'.')) {
            return $created;
        }

        return null;
    }
}
