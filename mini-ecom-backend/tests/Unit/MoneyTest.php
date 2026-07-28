<?php

use App\Support\Money;

test('rounding is half-up at two decimals', function (string $input, string $expected) {
    expect(Money::round($input))->toBe($expected);
})->with([
    'exact' => ['4.29', '4.29'],
    'rounds down below half' => ['1.824', '1.82'],
    'rounds up at exactly half' => ['8.3655', '8.37'],
    'rounds up above half' => ['1.9912', '1.99'],
    'the classic float trap' => ['2.675', '2.68'],
    'zero' => ['0', '0.00'],
    'negative rounds away from zero at half' => ['-2.675', '-2.68'],
]);

test('a line total is unit price times quantity, rounded once', function () {
    expect(Money::lineTotal('1.52', '1.310'))->toBe('1.99')
        ->and(Money::lineTotal('9.90', '0.845'))->toBe('8.37')
        ->and(Money::lineTotal('4.29', '2.000'))->toBe('8.58');
});

test('a subtotal sums already-rounded lines and does not re-round', function () {
    // Rounding each line then summing is reproducible and auditable: every figure on the
    // receipt is explained by a line the customer can see.
    expect(Money::sum(['8.58', '1.99', '5.49', '8.37']))->toBe('24.43');
});

test('summing unrounded lines would produce a different answer', function () {
    // Why the rule exists. Three lines of 0.005 each round to 0.01 apiece — a 0.03 subtotal —
    // whereas summing first gives 0.015, which rounds to 0.02. The second figure is one no
    // individual line accounts for.
    $perLine = Money::sum([Money::round('0.005'), Money::round('0.005'), Money::round('0.005')]);
    $summedFirst = Money::round(bcadd(bcadd('0.005', '0.005', 8), '0.005', 8));

    expect($perLine)->toBe('0.03')
        ->and($summedFirst)->toBe('0.02')
        ->and($perLine)->not->toBe($summedFirst);
});

test('floating point arithmetic disagrees with the decimal result', function () {
    // 0.1 + 0.2 is 0.30000000000000004 in every IEEE-754 parser. A total that round-trips
    // through a double comes back a cent short and cannot be reconciled against DECIMAL(10,2).
    expect(0.1 + 0.2)->not->toBe(0.3)
        ->and(Money::add('0.1', '0.2'))->toBe('0.30');
});

test('percentages round half-up to two decimals', function () {
    expect(Money::percentageOf('23.81', '5'))->toBe('1.19')   // 1.1905
        ->and(Money::percentageOf('24.43', '5'))->toBe('1.22') // 1.2215
        ->and(Money::percentageOf('28.99', '10'))->toBe('2.90');
});

test('the authorisation buffer is the estimate plus the weight tolerance', function () {
    $total = '28.99';

    expect(Money::add($total, Money::percentageOf($total, '10')))->toBe('31.89');
});

test('quantities carry three decimal places', function () {
    expect(Money::round('1.3105', Money::QUANTITY_SCALE))->toBe('1.311')
        ->and(Money::sub('12.500', '2.100', Money::QUANTITY_SCALE))->toBe('10.400');
});

test('comparison is exact rather than approximate', function () {
    expect(Money::compare('29.64', '31.89'))->toBe(-1)
        ->and(Money::compare('29.64', '29.64'))->toBe(0)
        ->and(Money::compare('31.89', '29.64'))->toBe(1)
        ->and(Money::isZero('0.00'))->toBeTrue();
});
