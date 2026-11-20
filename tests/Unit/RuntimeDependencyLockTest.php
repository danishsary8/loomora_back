<?php

it('locks a patched runtime version of :dataset', function (string $package, string $minimum) {
    $lock = json_decode(file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $versions = array_column($lock['packages'], 'version', 'name');

    expect($versions)->toHaveKey($package);
    expect(version_compare(ltrim($versions[$package], 'v'), $minimum, '>='))
        ->toBeTrue("{$package} {$versions[$package]} is below the patched release {$minimum}.");
})->with([
    'Laravel framework' => ['laravel/framework', '12.71.0'],
    'phpseclib' => ['phpseclib/phpseclib', '4.0.3'],
    'Symfony HTTP client' => ['symfony/http-client', '7.4.23'],
    'Symfony HTTP foundation' => ['symfony/http-foundation', '7.4.23'],
    'Symfony HTTP kernel' => ['symfony/http-kernel', '7.4.23'],
    'Symfony MIME' => ['symfony/mime', '7.4.22'],
    'Symfony routing' => ['symfony/routing', '7.4.23'],
]);
