/**
 * Normalize a MAC address to aa:bb:cc:dd:ee:ff.
 * Accepts colon, dash, or bare hex input.
 */
export function normalizeMacAddress(value: string): string | null {
    const trimmed = value.trim().toLowerCase();
    if (trimmed === '') {
        return null;
    }

    const hex = trimmed.replace(/[^0-9a-f]/g, '');
    if (hex.length !== 12) {
        return null;
    }

    const parts = hex.match(/.{2}/g);
    if (!parts || parts.length !== 6) {
        return null;
    }

    return parts.join(':');
}

/**
 * Strip separators and return lowercase hex for partial MAC matching.
 */
export function macAddressHex(value: string): string {
    return value.trim().toLowerCase().replace(/[^0-9a-f]/g, '');
}

export function isValidMacAddress(value: string): boolean {
    return normalizeMacAddress(value) !== null;
}
