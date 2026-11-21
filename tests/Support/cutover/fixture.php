<?php

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;

function check(bool $passed, string $label): void
{
    if (! $passed) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
}

// Run only through rehearse.sh, inside its unexposed, purpose-built containers.
check(getenv('B038_DISPOSABLE') === 'yes' && getenv('DB_HOST') === 'cutover-db'
    && getenv('DB_DATABASE') === 'b038_cutover' && ! getenv('DB_URL'), 'disposable configuration');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->instance('request', Request::create('http://localhost'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Fixture error: '.$error::class.' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    exit(1);
});
$connection = DB::connection();
check($connection->getDriverName() === 'pgsql' && $connection->getConfig('host') === 'cutover-db'
    && $connection->getDatabaseName() === 'b038_cutover' && ! $connection->getConfig('url'), 'effective configuration');
check(DB::scalar('select current_database()') === 'b038_cutover'
    && intdiv((int) DB::scalar('show server_version_num'), 10000) === 16, 'database identity');

function api(string $method, string $path, int $expected, array $body = [], ?string $bearer = null): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($bearer !== null) {
        $headers[] = 'Authorization: Bearer '.$bearer;
    }
    $stream = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'ignore_errors' => true, 'follow_location' => 0,
        'timeout' => 10, 'content' => $body ? json_encode($body, JSON_THROW_ON_ERROR) : '',
    ]]);
    $raw = file_get_contents('http://127.0.0.1:10000/api/v1/'.$path, false, $stream);
    $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
    check($status === $expected, "$method $path expected $expected, received $status");
    if ($expected === 204) {
        check($raw === '', 'empty 204 response');
    }

    return $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
}

/** Invoke the real callback with only the two upstream Google responses replaced. */
function callback(Kernel $kernel, User $user): string
{
    config(['services.google' => ['client_id' => 'fixture', 'client_secret' => 'fixture', 'redirect' => 'http://localhost']]);
    Socialite::forgetDrivers();
    $profile = ['sub' => $user->google_id, 'email' => $user->email, 'email_verified' => true, 'name' => $user->name];
    Socialite::driver('google')->setHttpClient(new Client(['handler' => new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'fixture'])),
        new Response(200, [], json_encode($profile)),
    ])]));
    $state = Crypt::encryptString(json_encode(['intent' => 'sign_in', 'expires' => now()->addMinutes(10)->timestamp]));
    $request = Request::create('/api/v1/auth/google/callback', 'GET', ['code' => 'fixture', 'state' => $state]);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    parse_str((string) parse_url($response->headers->get('Location') ?? '', PHP_URL_QUERY), $result);
    check($response->getStatusCode() === 302 && strlen($result['code'] ?? '') === 64, 'Google callback');

    return $result['code'];
}

/** Compare every original column in stable primary-key order, including historical rows. */
function snapshot(): array
{
    $counts = ['users' => 3, 'personal_access_tokens' => 3, 'password_reset_tokens' => 1, 'todos' => 2,
        'schedule_items' => 1, 'schedule_exceptions' => 1, 'schedule_checkins' => 1, 'daily_intentions' => 1];
    $digests = [];
    foreach ($counts as $table => $count) {
        $rows = DB::table($table)->orderBy($table === 'password_reset_tokens' ? 'email' : 'id')->get();
        check($rows->count() === $count, "$table count");
        foreach ($rows as $row) {
            if ($table === 'users') {
                unset($row->google_exchange_digest, $row->google_exchange_expires_at);
            }
        }
        $digests[$table] = hash('sha256', $rows->toJson(JSON_THROW_ON_ERROR));
    }

    return $digests;
}

function seed(Kernel $kernel): void
{
    check(DB::table('migrations')->count() === 15 && User::count() === 0, 'empty B-036 baseline');
    check(! Schema::hasColumn('users', 'google_exchange_digest'), 'B-036 schema');
    $state = [];
    foreach (['password', 'linked', 'google'] as $kind) {
        $user = User::create(['name' => $kind, 'email' => $kind.'@example.test', 'timezone' => 'Asia/Phnom_Penh',
            'password' => $kind === 'google' ? null : bin2hex(random_bytes(16))]);
        if ($kind !== 'password') {
            $user->forceFill(['google_id' => 'fixture-'.$kind, 'email_verified_at' => now()])->save();
        }
        $state['bearers'][$user->id] = $user->createToken('api', ['*'], now()->addDays(30))->plainTextToken;
    }
    $linked = User::where('name', 'linked')->sole();
    $linked->todos()->create(['title' => 'Active', 'category' => 'work']);
    $linked->todos()->create(['title' => 'Archived', 'category' => 'work'])->delete();
    $item = $linked->scheduleItems()->create(['title' => 'Study', 'category' => 'study', 'days' => [0, 1],
        'starts_on' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '10:00']);
    $item->exceptions()->create(['date' => '2026-11-09', 'type' => 'skip']);
    $item->checkins()->create(['date' => '2026-11-10', 'done_at' => '2026-11-10 09:15:00']);
    $linked->dailyIntentions()->create(['local_date' => '2026-11-10', 'text' => 'Finish reading']);
    $state['reset'] = Password::createToken($linked);
    $state['legacy'] = callback($kernel, $linked);
    $state['snapshot'] = snapshot();
    Cache::put('b038-fixture', Crypt::encrypt($state), now()->addMinutes(10));
    echo "PASS B-036: 15 migrations, baseline fixtures and legacy callback\n";
}

function verify(Kernel $kernel): void
{
    $state = Crypt::decrypt(Cache::pull('b038-fixture'));
    check(DB::table('migrations')->count() === 16, 'B-037 migrations');
    check(DB::table('migrations')->where('batch', 2)->pluck('migration')->all()
        === ['2026_11_21_000000_add_google_exchange_grant_to_users_table'], 'only the credential migration in batch two');
    $index = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'users')
        ->where('indexname', 'users_google_exchange_digest_unique')->value('indexdef');
    check(is_string($index) && str_starts_with($index, 'CREATE UNIQUE INDEX')
        && str_contains($index, '(google_exchange_digest)'), 'unique digest index');
    check($state['snapshot'] === snapshot(), 'all original rows and values');
    check(User::whereNull('google_exchange_digest')->whereNull('google_exchange_expires_at')->count() === 3, 'nullable columns');
    check(DB::table('todos')->whereNotNull('deleted_at')->count() === 1, 'historical to-do');
    foreach ($state['bearers'] as $id => $bearer) {
        check(api('GET', 'me', 200, bearer: $bearer)['data']['id'] === $id, 'original bearer UUID');
    }
    $linked = User::where('name', 'linked')->sole();
    check(Cache::get('google-code:'.hash('sha256', $state['legacy'])) === $linked->id, 'legacy grant still live in cache');
    check(isset(api('POST', 'auth/google/exchange', 422, ['code' => $state['legacy']])['errors']['code']), 'legacy rejection');
    check(DB::table('personal_access_tokens')->count() === 3, 'no bearer issued for the legacy grant');
    $fresh = ['code' => callback($kernel, $linked)];
    $session = api('POST', 'auth/google/exchange', 200, $fresh);
    check($session['user']['id'] === $linked->id && isset($session['token'], $session['expires_at']), 'fresh exchange');
    api('POST', 'auth/google/exchange', 422, $fresh);
    $pending = ['code' => callback($kernel, $linked)];
    api('POST', 'auth/password/reset', 200, ['email' => $linked->email, 'token' => $state['reset'],
        'password' => bin2hex(random_bytes(16))]);
    api('POST', 'auth/google/exchange', 422, $pending);
    api('GET', 'me', 401, bearer: $state['bearers'][$linked->id]);
    api('GET', 'me', 401, bearer: $session['token']);
    $signup = ['name' => 'Disposable', 'email' => 'replace@example.test',
        'password' => bin2hex(random_bytes(16)), 'timezone' => 'UTC'];
    $first = api('POST', 'auth/register', 201, $signup);
    api('DELETE', 'me', 204, ['password' => $signup['password']], $first['token']);
    $second = api('POST', 'auth/register', 201, $signup);
    check($first['user']['id'] !== $second['user']['id'], 'replacement UUID');
    api('GET', 'me', 401, bearer: $first['token']);
    check(api('GET', 'health?deep=1', 200) === ['status' => 'ok', 'database' => 'ok'], 'deep health');
    echo "PASS B-037: 16 migrations, 8 tables identical, 3 bearer identities, legacy rejection, fresh exchange, replay rejection, reset, revocation, deletion and re-registration\n";
}

match ($argv[1] ?? null) {
    'seed' => seed($kernel),
    'verify' => verify($kernel),
    default => exit(2),
};
