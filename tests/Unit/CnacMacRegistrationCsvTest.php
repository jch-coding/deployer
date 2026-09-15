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
