<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use RuntimeException;

class ImportExistingUsers extends Command
{
    protected $signature = 'auth:import-users {--expected-count= : Number of users in the verified source snapshot}';

    protected $description = 'Import an immutable user snapshot into auth_db without changing existing rows';

    public function handle(): int
    {
        if (DB::connection()->getDatabaseName() !== 'auth_db') {
            $this->error('The import is restricted to auth_db.');

            return self::FAILURE;
        }

        $expectedCount = filter_var($this->option('expected-count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($expectedCount === false) {
            $this->error('Provide a positive --expected-count from the verified source snapshot.');

            return self::FAILURE;
        }

        try {
            $importedCount = DB::transaction(function () use ($expectedCount): int {
                $seenIdentifiers = [];
                $importedCount = 0;

                while (($line = fgets(STDIN)) !== false) {
                    $user = $this->decodeUser($line);
                    $identifier = $user['id'];
                    if (isset($seenIdentifiers[$identifier])) {
                        throw new RuntimeException('Duplicate user identifier in import snapshot.');
                    }

                    $seenIdentifiers[$identifier] = true;
                    $existingUser = DB::table('users')->where('id', $identifier)->first();
                    if ($existingUser === null) {
                        DB::table('users')->insert($user);
                    } else {
                        foreach ($user as $field => $value) {
                            if ((string) ($existingUser->{$field} ?? '') !== (string) ($value ?? '')) {
                                throw new RuntimeException('An existing user differs from the import snapshot.');
                            }
                        }
                    }

                    $importedCount++;
                }

                if ($importedCount !== $expectedCount) {
                    throw new RuntimeException('The imported user count differs from the verified snapshot.');
                }

                return $importedCount;
            });
        } catch (JsonException|RuntimeException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Verified {$importedCount} unchanged users in auth_db.");

        return self::SUCCESS;
    }

    /** @return array<string, int|string|null> */
    private function decodeUser(string $line): array
    {
        $user = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $fields = ['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'];

        if (! is_array($user) || count($user) !== count($fields) || array_diff($fields, array_keys($user)) !== []
            || ! is_int($user['id']) || $user['id'] < 1
            || ! is_string($user['name']) || $user['name'] === ''
            || ! is_string($user['email']) || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($user['password']) || password_get_info($user['password'])['algo'] === null
            || ! $this->nullableString($user['email_verified_at'])
            || ! $this->nullableString($user['remember_token'])
            || ! $this->nullableString($user['created_at'])
            || ! $this->nullableString($user['updated_at'])) {
            throw new RuntimeException('The import snapshot contains an invalid user.');
        }

        return $user;
    }

    private function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }
}
