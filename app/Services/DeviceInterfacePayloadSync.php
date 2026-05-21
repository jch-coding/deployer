<?php

namespace App\Services;

use App\Models\DeviceInterface;

class DeviceInterfacePayloadSync
{
    public function __construct(
        protected DeviceInterfaceUpdateResolver $resolver = new DeviceInterfaceUpdateResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  dot-path => value
     */
    public function apply(DeviceInterface $interface, string $kind, array $attributes): void
    {
        $update = $this->resolver->payloadAttributesToUpdate($kind, $attributes, $interface);
        $resolved = $this->resolver->resolve($interface, $update);
        $this->resolver->applyResolved($interface, $resolved);
    }

    /**
     * @param  list<array{device_interface_id: int, kind: string, attributes: array<string, mixed>}>  $updates
     */
    public function applyMany(iterable $updates, iterable $interfacesById): void
    {
        foreach ($updates as $index => $row) {
            $interface = $interfacesById[(int) $row['device_interface_id']] ?? null;
            if ($interface === null) {
                continue;
            }

            $this->apply($interface, (string) $row['kind'], $row['attributes'] ?? []);
        }
    }
}
