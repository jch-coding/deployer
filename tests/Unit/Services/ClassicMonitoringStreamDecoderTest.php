<?php

use App\Services\Central\ClassicMonitoringStreamDecoder;

function encodeVarint(int $value): string
{
    $bytes = '';
    while ($value > 0x7F) {
        $bytes .= chr(($value & 0x7F) | 0x80);
        $value >>= 7;
    }

    return $bytes.chr($value & 0x7F);
}

function encodeTag(int $fieldNumber, int $wireType): string
{
    return encodeVarint(($fieldNumber << 3) | $wireType);
}

function encodeStringField(int $fieldNumber, string $value): string
{
    return encodeTag($fieldNumber, 2).encodeVarint(strlen($value)).$value;
}

function encodeVarintField(int $fieldNumber, int $value): string
{
    return encodeTag($fieldNumber, 0).encodeVarint($value);
}

function encodeMessageField(int $fieldNumber, string $message): string
{
    return encodeTag($fieldNumber, 2).encodeVarint(strlen($message)).$message;
}

function buildDeviceStateMessage(string $serial, int $status): string
{
    return encodeStringField(2, $serial).encodeVarintField(6, $status);
}

function buildMonitoringEnvelope(string $monitoringPayload): string
{
    return encodeMessageField(3, $monitoringPayload);
}

it('extracts up ap and switch serials from a monitoring frame', function () {
    $monitoring = encodeVarintField(2, ClassicMonitoringStreamDecoder::DATA_ELEMENT_STATE_AP)
        .encodeVarintField(2, ClassicMonitoringStreamDecoder::DATA_ELEMENT_STATE_SWITCH)
        .encodeMessageField(4, buildDeviceStateMessage('APSERIAL1', ClassicMonitoringStreamDecoder::STATUS_UP))
        .encodeMessageField(11, buildDeviceStateMessage('SWSERIAL1', ClassicMonitoringStreamDecoder::STATUS_UP));

    $frame = buildMonitoringEnvelope($monitoring);
    $serials = (new ClassicMonitoringStreamDecoder)->extractUpSerials($frame);

    expect($serials)->toBe(['APSERIAL1', 'SWSERIAL1']);
});

it('ignores down devices and empty frames', function () {
    $monitoring = encodeMessageField(4, buildDeviceStateMessage('APDOWN', ClassicMonitoringStreamDecoder::STATUS_DOWN))
        .encodeMessageField(11, buildDeviceStateMessage('SWDOWN', ClassicMonitoringStreamDecoder::STATUS_DOWN));

    expect((new ClassicMonitoringStreamDecoder)->extractUpSerials(buildMonitoringEnvelope($monitoring)))->toBe([])
        ->and((new ClassicMonitoringStreamDecoder)->extractUpSerials(''))->toBe([]);
});
