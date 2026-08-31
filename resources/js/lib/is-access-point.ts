type AccessPointLikeDevice = {
    device_type?: string;
    device_function?: string;
    deviceFunction?: string;
    model?: string;
};

export function isAccessPointDevice(device: AccessPointLikeDevice): boolean {
    const deviceType = (device.device_type ?? '').trim().toUpperCase();
    if (deviceType === 'ACCESS_POINT' || deviceType === 'AP' || deviceType === 'IAP') {
        return true;
    }

    const deviceFunction = (device.device_function ?? device.deviceFunction ?? '')
        .trim()
        .toUpperCase();
    if (
        deviceFunction !== '' &&
        (deviceFunction.includes('AP') ||
            deviceFunction.endsWith('_AP') ||
            deviceFunction === 'CAMPUS' ||
            deviceFunction === 'MICROBRANCH')
    ) {
        return true;
    }

    const model = (device.model ?? '').trim().toUpperCase();
    if (model.startsWith('AP-') || model.startsWith('IAP-')) {
        return true;
    }

    return false;
}
