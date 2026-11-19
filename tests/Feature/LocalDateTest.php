<?php

use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;

dataset('local date cases', function () {
    foreach (json_decode(file_get_contents(base_path('tests/Fixtures/local-date-cases.json')), true, flags: JSON_THROW_ON_ERROR) as $case) {
        yield $case['name'] => [$case];
    }
});

it('gives the local date of an instant', function (array $case) {
    $user = User::factory()->make(['timezone' => $case['timezone']]);

    expect(LocalDate::forUser($user, CarbonImmutable::parse($case['instant_utc'])))->toBe($case['local_date']);
})->with('local date cases');

it('gives the local date as today', function (array $case) {
    CarbonImmutable::setTestNow($case['instant_utc']);

    expect(LocalDate::todayFor(User::factory()->make(['timezone' => $case['timezone']])))->toBe($case['local_date']);

    CarbonImmutable::setTestNow();
})->with('local date cases');

it('gives the start and end of a local day in UTC', function (array $case) {
    $user = User::factory()->make(['timezone' => $case['timezone']]);

    [$start, $end] = LocalDate::rangeUtc($user, $case['local_date']);

    expect($start->format('Y-m-d\TH:i:s\Z'))->toBe($case['start_utc'])
        ->and($end->format('Y-m-d\TH:i:s\Z'))->toBe($case['end_utc'])
        ->and((int) round($start->diffInHours($end->addSecond())))->toBe($case['hours'])
        ->and($start->timezoneName)->toBe('UTC');
})->with('local date cases');

it('keeps the instant of the range inside its own local date', function (array $case) {
    $user = User::factory()->make(['timezone' => $case['timezone']]);
    [$start, $end] = LocalDate::rangeUtc($user, $case['local_date']);

    expect(LocalDate::forUser($user, $start))->toBe($case['local_date'])
        ->and(LocalDate::forUser($user, $end))->toBe($case['local_date'])
        ->and(LocalDate::forUser($user, $end->addSecond()))->not->toBe($case['local_date']);
})->with('local date cases');
