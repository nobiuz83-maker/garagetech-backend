<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('integration') || getenv('VICARTECH_SYNTHETIC_INTEGRATION') !== 'true'
    || ! str_starts_with(getenv('VICARTECH_INTEGRATION_PROJECT') ?: '', 'vicartech-lot1-integration-')) {
    throw new LogicException('Only a synthetic integration deployment is allowed.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$operation = $input['operation'];
if ($operation === 'seed-auth') {
    if (DB::table('users')->exists() || DB::table('companies')->exists()) {
        throw new LogicException('Synthetic seed requires empty Auth tables.');
    }
    DB::transaction(function () use ($input): void {
        foreach ($input['companies'] as $company) {
            DB::table('companies')->insert(['id' => $company['id'], 'display_name' => $company['name'], 'status' => 'active', 'authorization_version' => 1]);
        }
        foreach ($input['accounts'] as $account) {
            $user = App\Models\User::factory()->create(['name' => $account['name'], 'email' => $account['email'], 'password' => $account['password']]);
            foreach ($account['memberships'] as $membership) {
                $membershipId = (string) Str::uuid();
                DB::table('company_memberships')->insert(['id' => $membershipId, 'company_id' => $membership['companyId'], 'user_id' => $user->id, 'status' => 'active']);
                DB::table('company_membership_roles')->insert(['membership_id' => $membershipId, 'role_id' => $membership['role'], 'revoked_at' => null]);
            }
        }
    });
    echo json_encode(['users' => DB::table('users')->count(), 'companies' => DB::table('companies')->count()]);
} elseif ($operation === 'seed-projections') {
    if (DB::table('company_subjects')->exists()) {
        throw new LogicException('Synthetic projection seed requires empty tables.');
    }
    foreach ($input['companies'] as $company) {
        DB::table('company_subjects')->insert(['company_id' => $company['id'], 'status' => 'active', 'directory_version' => 1]);
        DB::table('company_security_policies')->insert(['company_id' => $company['id'], 'version' => 1, 'data' => '{"schemaVersion":1,"distinctAuthorPermissions":{}}']);
    }
    echo json_encode(['companies' => DB::table('company_subjects')->count()]);
} elseif ($operation === 'membership-change') {
    $owner = App\Models\User::query()->where('email', $input['ownerEmail'])->firstOrFail();
    $target = App\Models\User::query()->where('email', $input['targetEmail'])->firstOrFail();
    $request = Request::create('/integration-control', 'POST');
    $request->setUserResolver(fn () => $owner);
    $request->headers->set('X-Request-ID', (string) Str::uuid());
    $session = new Store('integration', new ArraySessionHandler(120));
    $session->put('auth.password_confirmed_at', now()->timestamp);
    $request->setLaravelSession($session);
    app(App\Services\CompanyMembershipManager::class)->change($request, $input['companyId'], (string) $target->id, $input['roles'], [], $input['status']);
    echo json_encode(['authorizationVersion' => DB::table('companies')->where('id', $input['companyId'])->value('authorization_version')]);
} elseif ($operation === 'age-sessions') {
    $user = App\Models\User::query()->where('email', $input['email'])->firstOrFail();
    foreach (DB::table('sessions')->where('user_id', $user->id)->get() as $session) {
        if ($input['kind'] === 'idle') {
            DB::table('sessions')->where('id', $session->id)->update(['last_activity' => now()->subMinutes(121)->timestamp]);
        } else {
            $payload = json_decode(base64_decode($session->payload), true, flags: JSON_THROW_ON_ERROR);
            $payload[$input['kind'] === 'absolute' ? 'company_security.started_at' : 'auth.password_confirmed_at'] = now()->subSeconds($input['kind'] === 'absolute' ? 43201 : 301)->timestamp;
            // Laravel stores dot-notation session keys as nested arrays.
            if ($input['kind'] === 'absolute') { $payload['company_security']['started_at'] = now()->subSeconds(43201)->timestamp; }
            if ($input['kind'] === 'recent') { $payload['auth']['password_confirmed_at'] = now()->subSeconds(301)->timestamp; }
            DB::table('sessions')->where('id', $session->id)->update(['payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))]);
        }
    }
    echo json_encode(['aged' => true]);
} elseif ($operation === 'db-evidence') {
    $tables = DB::select('SHOW TABLES');
    $counts = [];
    foreach ($tables as $table) {
        $name = array_values((array) $table)[0];
        $counts[$name] = DB::table($name)->count();
    }
    $foreignAccessDenied = false;
    try { DB::select('SELECT User FROM mysql.user'); } catch (Illuminate\Database\QueryException $failure) { $foreignAccessDenied = in_array($failure->errorInfo[1], [1044, 1142], true); }
    echo json_encode(['database' => DB::connection()->getDatabaseName(), 'host' => config('database.connections.mysql.host'), 'version' => DB::selectOne('SELECT VERSION() AS version')->version, 'tables' => $counts, 'administrativeAccessDenied' => $foreignAccessDenied]);
} elseif ($operation === 'audit-evidence') {
    echo json_encode(['audits' => DB::table('company_security_audits')->where('request_id', $input['requestId'])->get(['action', 'company_id', 'request_id'])->all()]);
} elseif ($operation === 'routes') {
    $routes = [];
    foreach (app('router')->getRoutes() as $route) { $routes[] = ['uri' => '/'.$route->uri(), 'methods' => $route->methods()]; }
    echo json_encode($routes);
} else {
    throw new LogicException('Unknown synthetic operation.');
}
