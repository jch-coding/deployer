<?php

use App\Support\MacAddressTableParser;

$sampleOutput = <<<'OUTPUT'
MAC age-time            : 300 seconds
Number of MAC addresses : 324

MAC Address          VLAN     Type                      Port      
--------------------------------------------------------------
88:22:5b:7d:ff:00    1        dynamic                   1/1/52     
88:22:5b:7d:ff:80    1        dynamic                   1/1/52     
e8:1c:a5:72:ad:c0    1        dynamic                   1/1/52     
e8:1c:a5:72:be:00    1        dynamic                   1/1/52     
e8:1c:a5:72:ef:00    1        dynamic                   1/1/52  
OUTPUT;

it('parses preamble metadata and table rows', function () use ($sampleOutput) {
    $result = MacAddressTableParser::parse($sampleOutput);

    expect($result['ageTime'])->toBe('300 seconds')
        ->and($result['count'])->toBe(324)
        ->and($result['rows'])->toHaveCount(5)
        ->and($result['rows'][0])->toBe([
            'mac' => '88:22:5b:7d:ff:00',
            'vlan' => '1',
            'type' => 'dynamic',
            'port' => '1/1/52',
        ])
        ->and($result['rows'][4])->toBe([
            'mac' => 'e8:1c:a5:72:ef:00',
            'vlan' => '1',
            'type' => 'dynamic',
            'port' => '1/1/52',
        ]);
});

it('returns empty rows when output is unparseable', function () {
    $result = MacAddressTableParser::parse('no table here');

    expect($result['rows'])->toBe([])
        ->and($result['ageTime'])->toBeNull()
        ->and($result['count'])->toBeNull()
        ->and($result['raw'])->toBe('no table here');
});
