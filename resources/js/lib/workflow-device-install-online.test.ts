import { describe, expect, it } from 'vitest';
import {
    installOnlineBadgeClass,
    installOnlineBadgeKey,
    installOnlineBadgeLabel,
} from './workflow-device-install-online';

describe('installOnlineBadgeKey', () => {
    it('maps installed and online combinations', () => {
        expect(installOnlineBadgeKey(true, true)).toBe('installed_online');
        expect(installOnlineBadgeKey(true, false)).toBe('installed_offline');
        expect(installOnlineBadgeKey(false, true)).toBe('not_installed_online');
        expect(installOnlineBadgeKey(false, false)).toBe(
            'not_installed_offline',
        );
    });

    it('treats null or undefined is_installed as not installed', () => {
        expect(installOnlineBadgeKey(null, true)).toBe('not_installed_online');
        expect(installOnlineBadgeKey(undefined, false)).toBe(
            'not_installed_offline',
        );
    });
});

describe('installOnlineBadgeLabel', () => {
    it('returns human-readable labels', () => {
        expect(installOnlineBadgeLabel('installed_online')).toBe(
            'Installed + Online',
        );
        expect(installOnlineBadgeLabel('not_installed_offline')).toBe(
            'Not installed + Offline',
        );
    });
});

describe('installOnlineBadgeClass', () => {
    it('returns a class for each badge key', () => {
        expect(installOnlineBadgeClass('installed_online')).toContain(
            'emerald',
        );
        expect(installOnlineBadgeClass('installed_offline')).toContain('amber');
        expect(installOnlineBadgeClass('not_installed_online')).toContain(
            'blue',
        );
        expect(installOnlineBadgeClass('not_installed_offline')).toContain(
            'red',
        );
    });
});
