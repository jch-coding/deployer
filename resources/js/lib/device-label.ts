/**
 * Display label for a device: explicit hostname when set, otherwise serial.
 */
export function deviceHasExplicitName(name: string, serial: string): boolean {
    const trimmedName = name.trim();
    const trimmedSerial = serial.trim();

    return trimmedName !== '' && trimmedName !== trimmedSerial;
}

export function formatDeviceLabel(name: string, serial: string): string {
    return deviceHasExplicitName(name, serial) ? name.trim() : serial.trim();
}

export function isApDevice(deviceFunction: string | undefined | null): boolean {
    return (deviceFunction ?? '').toUpperCase().includes('AP');
}
