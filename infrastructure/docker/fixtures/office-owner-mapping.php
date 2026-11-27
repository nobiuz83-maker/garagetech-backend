<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$application = require '/var/www/html/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

if ($application->environment() !== 'integration'
    || getenv('VICARTECH_SYNTHETIC_INTEGRATION') !== 'true'
    || getenv('VICARTECH_INTEGRATION_PROJECT') !== 'vicartech-lot1-integration-office') {
    throw new LogicException('Only the isolated synthetic OFFICE project is allowed.');
}

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$companyId = $input['companyId'] ?? null;
if (! is_string($companyId) || ! \Illuminate\Support\Str::isUuid($companyId)) {
    throw new InvalidArgumentException('A valid company UUID is required.');
}

if (($input['operation'] ?? null) === 'owner-subject') {
    $email = $input['email'] ?? null;
    if (! is_string($email)) {
        throw new InvalidArgumentException('An owner email is required.');
    }
    $ownerSubjectId = DB::table('users')
        ->join('company_memberships', 'users.id', '=', 'company_memberships.user_id')
        ->join('company_membership_roles', 'company_memberships.id', '=', 'company_membership_roles.membership_id')
        ->where('users.email', $email)
        ->where('company_memberships.company_id', $companyId)
        ->where('company_memberships.status', 'active')
        ->where('company_membership_roles.role_id', 'owner')
        ->value('users.id');
    if ($ownerSubjectId === null) {
        throw new RuntimeException('Synthetic OFFICE owner membership is missing.');
    }
    echo json_encode(['ownerSubjectId' => (int) $ownerSubjectId], JSON_THROW_ON_ERROR);
} elseif (($input['operation'] ?? null) === 'map-owner') {
    $ownerSubjectId = $input['ownerSubjectId'] ?? null;
    if (! is_int($ownerSubjectId) || $ownerSubjectId < 1
        || ! DB::table('company_subjects')->where('company_id', $companyId)->exists()) {
        throw new InvalidArgumentException('An active OFFICE company and owner subject are required.');
    }
    $existingMapping = DB::table('intervention_legacy_owner_mappings')->where('company_id', $companyId)->first();
    if ($existingMapping !== null && (int) $existingMapping->owner_subject_id !== $ownerSubjectId) {
        throw new RuntimeException('OFFICE owner mapping conflicts with an existing mapping.');
    }
    if ($existingMapping === null) {
        DB::table('intervention_legacy_owner_mappings')->insert([
            'company_id' => $companyId,
            'owner_subject_id' => $ownerSubjectId,
            'created_at' => now(),
        ]);
    }
    echo json_encode(['mapped' => true], JSON_THROW_ON_ERROR);
} else {
    throw new InvalidArgumentException('Unknown OFFICE provisioning operation.');
}
