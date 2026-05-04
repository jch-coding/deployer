<?php

use App\Helper\BooleanHelper;

it('parses strict CSV boolean cells with empty as blank string', function () {
    expect(BooleanHelper::parseCsvBoolean(''))->toBe('');
    expect(BooleanHelper::parseCsvBoolean(null))->toBe('');
    expect(BooleanHelper::parseCsvBoolean('   '))->toBe('');
});

it('parses strict CSV boolean cells case-insensitively', function () {
    expect(BooleanHelper::parseCsvBoolean(' TRUE '))->toBeTrue();
    expect(BooleanHelper::parseCsvBoolean('No'))->toBeFalse();
    expect(BooleanHelper::parseCsvBoolean('1'))->toBeTrue();
    expect(BooleanHelper::parseCsvBoolean('0'))->toBeFalse();
    expect(BooleanHelper::parseCsvBoolean('Yes'))->toBeTrue();
});

it('rejects invalid strict CSV boolean values', function () {
    expect(fn () => BooleanHelper::parseCsvBoolean('maybe'))->toThrow(\InvalidArgumentException::class);
});

it('coerces lenient booleans for domain code', function () {
    expect(BooleanHelper::toBoolean('yes'))->toBeTrue();
    expect(BooleanHelper::toBoolean('no'))->toBeFalse();
    expect(BooleanHelper::toBoolean('true'))->toBeTrue();
    expect(BooleanHelper::toBoolean(''))->toBeFalse();
});
