<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$fixture = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$connection = DB::connection();
echo $connection->selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL;
flush();

if (isset($fixture['pause_before'])) {
    $pauseBefore = $fixture['pause_before'];
    $connection->beforeExecuting(function (string $sql) use ($connection, &$pauseBefore): void {
        if ($pauseBefore !== '' && str_starts_with($sql, $pauseBefore)) {
            $pauseBefore = '';
            // Use PDO directly to avoid invoking this callback from the barrier query.
            $connection->getPdo()->query('select pg_advisory_lock(36036)');
        }
    });
}

if (isset($fixture['google'])) {
    $google = (new GoogleUser)->setRaw(['email_verified' => true])->map($fixture['google']);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $state = Crypt::encryptString(json_encode([
        'intent' => $fixture['intent'],
        'expires' => now()->addMinutes(10)->timestamp,
    ], JSON_THROW_ON_ERROR));
    $fixture['path'] .= '?'.http_build_query(['state' => $state]);
}

$request = Request::create($fixture['path'], $fixture['method'], server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => isset($fixture['bearer']) ? 'Bearer '.$fixture['bearer'] : '',
], content: json_encode($fixture['body'], JSON_THROW_ON_ERROR));

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
    'location' => $response->headers->get('Location'),
], JSON_THROW_ON_ERROR).PHP_EOL;
