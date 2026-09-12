import { describe, expect, it } from 'vitest';
import {
    deviceHasExplicitName,
    formatDeviceLabel,
    formatDeviceTitle,
} from './device-label';

describe('deviceHasExplicitName', () => {
    it('returns true when name is set and differs from serial', () => {
        expect(deviceHasExplicitName('Switch-A', 'SN12345')).toBe(true);
    });

    it('returns false when name is empty', () => {
        expect(deviceHasExplicitName('', 'SN12345')).toBe(false);
        expect(deviceHasExplicitName('   ', 'SN12345')).toBe(false);
    });

    it('returns false when name equals serial', () => {
        expect(deviceHasExplicitName('SN12345', 'SN12345')).toBe(false);
        expect(deviceHasExplicitName(' SN12345 ', 'SN12345')).toBe(false);
    });
});

describe('formatDeviceLabel', () => {
    it('returns the name when explicit', () => {
        expect(formatDeviceLabel('Switch-A', 'SN12345')).toBe('Switch-A');
    });

    it('returns the serial when name is missing or equal', () => {
        expect(formatDeviceLabel('', 'SN12345')).toBe('SN12345');
        expect(formatDeviceLabel('SN12345', 'SN12345')).toBe('SN12345');
    });
});

describe('formatDeviceTitle', () => {
    it('returns Name (Serial) when an explicit name exists', () => {
        expect(formatDeviceTitle('Switch-A', 'SN12345')).toBe('Switch-A (SN12345)');
    });

    it('returns serial alone when name is empty', () => {
        expect(formatDeviceTitle('', 'SN12345')).toBe('SN12345');
    });

    it('returns serial alone when name equals serial', () => {
        expect(formatDeviceTitle('SN12345', 'SN12345')).toBe('SN12345');
    });
});
