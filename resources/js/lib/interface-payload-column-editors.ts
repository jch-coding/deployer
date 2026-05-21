export const INTERFACE_MODES = ['ACCESS', 'TRUNK'] as const;
export const LACP_MODES = ['ACTIVE', 'PASSIVE', 'AUTO'] as const;
export const LACP_RATES = ['FAST', 'SLOW'] as const;
export const LACP_TRUNK_TYPES = [
    'LACP',
    'TRUNK',
    'DT_TRUNK',
    'MULTI_CHASSIS',
    'MULTI_CHASSIS_STATIC',
] as const;

export const BOOLEAN_STRING_OPTIONS = ['true', 'false'] as const;

const LAG_BOOLEAN_PATHS = new Set([
    'enable',
    'switchport.trunk-vlan-all',
    'vsx.shutdown-on-split',
    'stp.admin-edge-port',
    'stp.admin-edge-port-trunk',
    'stp.bpdu-guard',
    'stp.loop-guard',
]);

const LAG_ENUM_PATHS: Record<string, readonly string[]> = {
    'lacp.mode': LACP_MODES,
    'lacp.rate': LACP_RATES,
    'trunk-type': LACP_TRUNK_TYPES,
    'switchport.interface-mode': INTERFACE_MODES,
};

export type PathEditorType = 'text' | 'enum' | 'boolean' | 'port-list';

export type PathEditorConfig = {
    type: PathEditorType;
    options?: readonly string[];
};

export function getLagPathEditor(path: string): PathEditorConfig {
    if (path === 'port-list') {
        return { type: 'port-list' };
    }
    if (LAG_BOOLEAN_PATHS.has(path)) {
        return { type: 'boolean', options: BOOLEAN_STRING_OPTIONS };
    }
    const enumOptions = LAG_ENUM_PATHS[path];
    if (enumOptions) {
        return { type: 'enum', options: enumOptions };
    }

    return { type: 'text' };
}

export function formatPayloadCellValue(path: string, value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (path === 'port-list') {
        if (Array.isArray(value)) {
            return value.map((part) => String(part).trim()).filter(Boolean).join(', ');
        }
        const stringValue = String(value);
        if (stringValue.includes('&')) {
            return stringValue
                .split('&')
                .map((part) => part.trim())
                .filter(Boolean)
                .join(', ');
        }

        return stringValue;
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

export function parsePayloadCellValue(path: string, raw: string): unknown {
    const editor = path === 'port-list' ? { type: 'port-list' as const } : getLagPathEditor(path);

    if (editor.type === 'port-list') {
        if (raw.trim() === '') {
            return [];
        }

        return raw
            .split(',')
            .map((part) => part.trim())
            .filter(Boolean);
    }

    if (editor.type === 'boolean') {
        if (raw === 'true') {
            return true;
        }
        if (raw === 'false') {
            return false;
        }

        return raw;
    }

    return raw;
}
