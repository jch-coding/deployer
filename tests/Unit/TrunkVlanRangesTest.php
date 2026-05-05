<?php

use App\Support\TrunkVlanRanges;
use Illuminate\Validation\ValidationException;

it('returns null for null, empty string, and whitespace', function () {
    expect(TrunkVlanRanges::normalizeForStorage(null))->toBeNull()
        ->and(TrunkVlanRanges::normalizeForStorage(''))->toBeNull()
        ->and(TrunkVlanRanges::normalizeForStorage('   '))->toBeNull();
});

it('normalizes comma-separated and ampersand-separated input to canonical form', function () {
    expect(TrunkVlanRanges::normalizeForStorage('100,200-220'))->toBe('100&200-220')
        ->and(TrunkVlanRanges::normalizeForStorage('100&200-220'))->toBe('100&200-220');
});

it('collapses contiguous VLANs into a single range segment', function () {
    expect(TrunkVlanRanges::normalizeForStorage('10,11,12'))->toBe('10-12')
        ->and(TrunkVlanRanges::normalizeForStorage('10&11&12'))->toBe('10-12');
});

it('accepts array segment lists', function () {
    expect(TrunkVlanRanges::normalizeForStorage(['10', '20-22']))->toBe('10&20-22');
});

it('throws on inverted range', function () {
    TrunkVlanRanges::normalizeForStorage('5-3');
})->throws(ValidationException::class);

it('throws on VLAN below minimum', function () {
    TrunkVlanRanges::normalizeForStorage('0');
})->throws(ValidationException::class);

it('throws on VLAN above maximum', function () {
    TrunkVlanRanges::normalizeForStorage('5000');
})->throws(ValidationException::class);

it('throws on invalid token', function () {
    TrunkVlanRanges::normalizeForStorage('abc');
})->throws(ValidationException::class);

it('formats canonical string for display with comma spacing', function () {
    expect(TrunkVlanRanges::toDisplayString('100&200-220'))->toBe('100, 200-220')
        ->and(TrunkVlanRanges::toDisplayString(null))->toBe('')
        ->and(TrunkVlanRanges::toDisplayString(''))->toBe('');
});
