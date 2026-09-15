<?php

use App\Support\CnacMacRegistrationCsv;

it('parses Central NAC MAC registration CSV with multi-tag quoting and mixed MAC formats', function () {
    $csv = <<<'CSV'
MAC Address,Client Name,Enabled,Static Tags
F4-9A-B1-C0-A5-86,,true,"BVSD-FACILITIES, BVSD-PUBLIC"
aa:bb:cc:dd:ee:01,Lobby Phone,false,TAG-A
EC1B5FCBE66D,Desk AP,yes,
CSV;

    $result = CnacMacRegistrationCsv::parse($csv);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(3)
        ->and($result['entries'][0])->toMatchArray([
            'mac_address' => 'f4:9a:b1:c0:a5:86',
            'client_name' => '',
            'enabled' => true,
            'static_tags' => ['BVSD-FACILITIES', 'BVSD-PUBLIC'],
        ])
        ->and($result['entries'][1])->toMatchArray([
            'mac_address' => 'aa:bb:cc:dd:ee:01',
            'client_name' => 'Lobby Phone',
            'enabled' => false,
            'static_tags' => ['TAG-A'],
        ])
        ->and($result['entries'][2])->toMatchArray([
            'mac_address' => 'ec:1b:5f:cb:e6:6d',
            'client_name' => 'Desk AP',
            'enabled' => true,
            'static_tags' => [],
        ]);
});

it('returns an error when MAC Address column is missing', function () {
    $result = CnacMacRegistrationCsv::parse("Client Name,Enabled\nfoo,true\n");

    expect($result['entries'])->toBe([])
        ->and($result['error'])->toContain('MAC Address');
});

it('skips invalid MAC rows', function () {
    $csv = <<<'CSV'
MAC Address,Client Name,Enabled,Static Tags
not-a-mac,,true,
AA-BB-CC-DD-EE-FF,Valid,true,TAG
CSV;

    $result = CnacMacRegistrationCsv::parse($csv);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['mac_address'])->toBe('aa:bb:cc:dd:ee:ff');
});

it('parses static tags with commas and whitespace', function () {
    expect(CnacMacRegistrationCsv::parseStaticTags(''))->toBe([])
        ->and(CnacMacRegistrationCsv::parseStaticTags(' A , B , '))->toBe(['A', 'B']);
});

it('parses camelCase export headers and BOM-prefixed CSV', function () {
    $csv = "\xEF\xBB\xBFmacAddress,displayName,enable,staticTags\n"
        ."AA-BB-CC-DD-EE-01,Lobby,true,\"TAG-A, TAG-B\"\n";

    $result = CnacMacRegistrationCsv::parse($csv);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0])->toMatchArray([
            'mac_address' => 'aa:bb:cc:dd:ee:01',
            'client_name' => 'Lobby',
            'enabled' => true,
            'static_tags' => ['TAG-A', 'TAG-B'],
        ]);
});

it('unwraps JSON-encoded CSV string payloads', function () {
    $inner = "MAC Address,Client Name,Enabled,Static Tags\nAA-BB-CC-DD-EE-02,,true,TAG\n";
    $payload = json_encode($inner, JSON_THROW_ON_ERROR);

    $result = CnacMacRegistrationCsv::parse($payload);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['mac_address'])->toBe('aa:bb:cc:dd:ee:02');
});

it('parses JSON list export payloads with macAddress fields', function () {
    $payload = json_encode([
        'count' => 2,
        'items' => [
            [
                'macAddress' => 'AA-BB-CC-DD-EE-01',
                'displayName' => 'Lobby',
                'enable' => true,
                'staticTags' => ['TAG-A', 'TAG-B'],
            ],
            [
                'macAddress' => '11:22:33:44:55:66',
                'displayName' => '',
                'enable' => false,
                'staticTags' => [],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = CnacMacRegistrationCsv::parse($payload);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(2)
        ->and($result['entries'][0])->toMatchArray([
            'mac_address' => 'aa:bb:cc:dd:ee:01',
            'client_name' => 'Lobby',
            'enabled' => true,
            'static_tags' => ['TAG-A', 'TAG-B'],
        ])
        ->and($result['entries'][1])->toMatchArray([
            'mac_address' => '11:22:33:44:55:66',
            'client_name' => '',
            'enabled' => false,
            'static_tags' => [],
        ]);
});

it('treats empty JSON items list as a successful empty export', function () {
    $result = CnacMacRegistrationCsv::parse('{"count":0,"items":[]}');

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toBe([]);
});

it('builds import CSV with per-row static tags', function () {
    $csv = CnacMacRegistrationCsv::buildImportCsv([
        [
            'mac_address' => 'aa:bb:cc:dd:ee:01',
            'static_tags' => ['TAG-A', 'TAG-B'],
        ],
        [
            'mac_address' => 'aa:bb:cc:dd:ee:02',
            'static_tags' => ['TAG-NEW'],
        ],
    ]);

    expect($csv)->toContain('MAC Address')
        ->and($csv)->toContain('Client Name')
        ->and($csv)->toContain('Enabled')
        ->and($csv)->toContain('Static Tags')
        ->and($csv)->toContain('aa:bb:cc:dd:ee:01')
        ->and($csv)->toContain('TAG-A, TAG-B')
        ->and($csv)->toContain('aa:bb:cc:dd:ee:02')
        ->and($csv)->toContain('TAG-NEW');
});
