<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProvisionCompany extends Command
{
    protected $signature = 'auth:provision-company {display-name} {owner-id} {owner-email}';

    protected $description = 'Create an independent company and assign its confirmed initial owner';

    public function handle(): int
    {
        if (DB::connection()->getDatabaseName() !== 'auth_db' && ! app()->environment('testing')) {
            $this->error('Company provisioning is restricted to auth_db.');

            return self::FAILURE;
        }

        $displayName = trim((string) $this->argument('display-name'));
        $ownerId = filter_var($this->argument('owner-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ownerEmail = strtolower(trim((string) $this->argument('owner-email')));
        if ($displayName === '' || mb_strlen($displayName) > 255 || $ownerId === false || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid company name and the confirmed owner ID and email.');

            return self::FAILURE;
        }

        try {
            $companyId = DB::transaction(function () use ($displayName, $ownerId, $ownerEmail): string {
                $owner = DB::table('users')->where('id', $ownerId)->lockForUpdate()->first();
                if ($owner === null || strtolower($owner->email) !== $ownerEmail) {
                    throw new RuntimeException('The confirmed owner ID and email do not match an AuthService account.');
                }

                $existingCompany = DB::table('companies')->whereRaw('LOWER(display_name) = ?', [mb_strtolower($displayName)])->lockForUpdate()->first();
                if ($existingCompany !== null) {
                    $membership = DB::table('company_memberships')->where('company_id', $existingCompany->id)->where('user_id', $ownerId)->first();
                    $isOwner = $membership !== null && $membership->status === 'active'
                        && DB::table('company_membership_roles')->where('membership_id', $membership->id)->where('role_id', 'owner')->whereNull('revoked_at')->exists();
                    if ($existingCompany->display_name === $displayName && $isOwner) {
                        return $existingCompany->id;
                    }

                    throw new RuntimeException('A company with this name already exists and was left unchanged.');
                }

                $companyId = (string) Str::uuid();
                $membershipId = (string) Str::uuid();
                $requestId = (string) Str::uuid();
                DB::table('companies')->insert([
                    'id' => $companyId,
                    'display_name' => $displayName,
                    'status' => 'active',
                    'authorization_version' => 1,
                ]);
                DB::table('company_memberships')->insert([
                    'id' => $membershipId,
                    'company_id' => $companyId,
                    'user_id' => $ownerId,
                    'status' => 'active',
                ]);
                DB::table('company_membership_roles')->insert([
                    'membership_id' => $membershipId,
                    'role_id' => 'owner',
                    'revoked_at' => null,
                ]);
                DB::table('company_security_audits')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'actor_subject' => (string) $ownerId,
                    'request_id' => $requestId,
                    'action' => 'company.provisioned',
                    'details' => json_encode(['schemaVersion' => 1, 'displayName' => $displayName, 'ownerSubject' => $ownerId], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                ]);

                return $companyId;
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Company {$displayName} is available as {$companyId}.");

        return self::SUCCESS;
    }
}
