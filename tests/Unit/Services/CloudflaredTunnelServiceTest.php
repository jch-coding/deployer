<?php

use App\Services\Cloudflare\CloudflaredTunnelService;

it('merges ingress hostnames when writing config.yml', function () {
    $dir = sys_get_temp_dir().'/cloudflared-test-'.uniqid();
    mkdir($dir, 0700, true);
    putenv('CLOUDFLARED_CONFIG_DIR='.$dir);
    $_ENV['CLOUDFLARED_CONFIG_DIR'] = $dir;
    $_SERVER['CLOUDFLARED_CONFIG_DIR'] = $dir;

    $service = new CloudflaredTunnelService;

    $creds = $dir.'/aaaa-bbbb.json';
    file_put_contents($creds, '{}');
    file_put_contents($dir.'/config.yml', <<<YAML
# name: deployer
tunnel: aaaa-bbbb
credentials-file: {$creds}

ingress:
  - hostname: first.example.com
    service: http://127.0.0.1:80
  - service: http_status:404
YAML);

    $service->writeConfig('aaaa-bbbb', $creds, 'second.example.com', 'deployer');
    $config = $service->readConfig();

    $hostnames = collect($config['ingress'])
        ->pluck('hostname')
        ->filter()
        ->values()
        ->all();

    expect($hostnames)->toBe(['first.example.com', 'second.example.com'])
        ->and($config['tunnel'])->toBe('aaaa-bbbb')
        ->and($config['name'])->toBe('deployer');

    // cleanup
    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
    putenv('CLOUDFLARED_CONFIG_DIR');
    unset($_ENV['CLOUDFLARED_CONFIG_DIR'], $_SERVER['CLOUDFLARED_CONFIG_DIR']);
});

it('rejects invalid tunnel names and hostnames', function () {
    $service = new CloudflaredTunnelService;

    expect(fn () => $service->assertValidName('bad name'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $service->assertValidHostname('not a host'))
        ->toThrow(RuntimeException::class);

    $service->assertValidName('deployer');
    $service->assertValidHostname('app.example.com');
});
