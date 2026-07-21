<?php

namespace App\Services\Central;

/**
 * Decodes Classic Central Streaming API monitoring frames using documented
 * protobuf field numbers (MsgProto + MonitoringInformation Ap/Switch state).
 */
class ClassicMonitoringStreamDecoder
{
    public const STATUS_UP = 1;

    public const STATUS_DOWN = 2;

    public const DATA_ELEMENT_STATE_SWITCH = 2;

    public const DATA_ELEMENT_STATE_AP = 4;

    /**
     * @return list<string> Device serials reported as UP in this frame
     */
    public function extractUpSerials(string $frame): array
    {
        $decoded = $this->decodeMonitoringFrame($frame);
        if ($decoded === null) {
            return [];
        }

        $serials = [];
        foreach (array_merge($decoded['aps'], $decoded['switches']) as $device) {
            if (($device['status'] ?? null) === self::STATUS_UP && ($device['serial'] ?? '') !== '') {
                $serials[] = $device['serial'];
            }
        }

        return array_values(array_unique($serials));
    }

    /**
     * @return array{
     *     subject: string|null,
     *     customer_id: string|null,
     *     timestamp: int|null,
     *     aps: list<array{serial: string, status: int}>,
     *     switches: list<array{serial: string, status: int}>,
     *     data_elements: list<int>
     * }|null
     */
    public function decodeMonitoringFrame(string $frame): ?array
    {
        if ($frame === '') {
            return null;
        }

        $envelope = $this->decodeKeyValues($frame);
        $payload = $envelope[3][0] ?? null;
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $subject = isset($envelope[2][0]) && is_string($envelope[2][0]) ? $envelope[2][0] : null;
        $timestamp = isset($envelope[4][0]) ? (int) $envelope[4][0] : null;
        $envelopeCustomerId = isset($envelope[5][0]) && is_string($envelope[5][0]) ? $envelope[5][0] : null;

        $monitoring = $this->decodeKeyValues($payload);
        $customerId = isset($monitoring[1][0]) && is_string($monitoring[1][0])
            ? $monitoring[1][0]
            : $envelopeCustomerId;

        $dataElements = [];
        foreach ($monitoring[2] ?? [] as $element) {
            $dataElements[] = (int) $element;
        }

        $aps = [];
        foreach ($monitoring[4] ?? [] as $apBytes) {
            if (! is_string($apBytes)) {
                continue;
            }
            $device = $this->decodeDeviceState($apBytes);
            if ($device !== null) {
                $aps[] = $device;
            }
        }

        $switches = [];
        foreach ($monitoring[11] ?? [] as $switchBytes) {
            if (! is_string($switchBytes)) {
                continue;
            }
            $device = $this->decodeDeviceState($switchBytes);
            if ($device !== null) {
                $switches[] = $device;
            }
        }

        return [
            'subject' => $subject,
            'customer_id' => $customerId,
            'timestamp' => $timestamp,
            'aps' => $aps,
            'switches' => $switches,
            'data_elements' => $dataElements,
        ];
    }

    /**
     * @return array{serial: string, status: int}|null
     */
    private function decodeDeviceState(string $message): ?array
    {
        $fields = $this->decodeKeyValues($message);
        $serial = isset($fields[2][0]) && is_string($fields[2][0]) ? trim($fields[2][0]) : '';
        if ($serial === '') {
            return null;
        }

        $status = isset($fields[6][0]) ? (int) $fields[6][0] : self::STATUS_UP;

        return [
            'serial' => $serial,
            'status' => $status,
        ];
    }

    /**
     * @return array<int, list<int|string>>
     */
    private function decodeKeyValues(string $bytes): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $tag = $this->readVarint($bytes, $offset);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x07;

            if ($fieldNumber === 0) {
                break;
            }

            if ($wireType === 0) {
                $result[$fieldNumber][] = $this->readVarint($bytes, $offset);
            } elseif ($wireType === 2) {
                $len = $this->readVarint($bytes, $offset);
                if ($offset + $len > $length) {
                    break;
                }
                $result[$fieldNumber][] = substr($bytes, $offset, $len);
                $offset += $len;
            } elseif ($wireType === 1) {
                $offset += 8;
            } elseif ($wireType === 5) {
                $offset += 4;
            } else {
                break;
            }
        }

        return $result;
    }

    private function readVarint(string $bytes, int &$offset): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $byte = ord($bytes[$offset]);
            $offset++;
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
            if ($shift > 63) {
                break;
            }
        }

        return $result;
    }
}
