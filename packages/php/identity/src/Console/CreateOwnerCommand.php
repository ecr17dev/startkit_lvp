<?php

namespace Starter\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Starter\Administration\Audit\Auditor;
use Starter\Identity\Authorization\PermissionSynchronizer;
use Starter\Identity\Contracts\IdentityUser;
use Starter\Identity\Identity;
use Starter\Identity\Otp\OtpBroker;
use Starter\Identity\Permissions;

/**
 * Prepare the owner from a trusted shell. The first public visitor never
 * receives privileges: the owner verifies the email with a one-time code
 * on the first login.
 */
class CreateOwnerCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:owner
        {email : Email address of the owner}
        {--name= : Display name (asked during onboarding when omitted)}';

    /**
     * @var string
     */
    protected $description = 'Create the owner account or promote an existing user to owner';

    public function handle(PermissionSynchronizer $permissions, Auditor $auditor): int
    {
        $email = OtpBroker::normalizeEmail((string) $this->argument('email'));
        $name = trim((string) $this->option('name'));

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email:strict', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $permissions->sync();
        $model = Identity::userModel();

        $user = DB::transaction(function () use ($model, $email, $name) {
            $user = $model::query()->withoutGlobalScopes()->where('email', $email)->first();

            if ($user === null) {
                $user = new $model;
                $user->forceFill(['email' => $email, 'name' => $name, 'is_active' => true])->save();
            } else {
                $user->forceFill(array_filter(['name' => $name, 'is_active' => true]))->save();
            }

            return $user;
        });

        if (! $user instanceof IdentityUser) {
            $this->components->error(sprintf('The user model must implement %s.', IdentityUser::class));

            return self::FAILURE;
        }

        $created = $user->wasRecentlyCreated;
        $user->assignRole(Permissions::OWNER);

        $auditor->via(Auditor::CHANNEL_CLI, fn () => $auditor->record(
            $created ? 'owner.created' : 'owner.promoted',
            $user,
            ['email' => $email],
        ));

        $this->components->info($created
            ? "Owner {$email} created."
            : "{$email} is now an owner.");

        $this->components->bulletList([
            'Log in at '.url('/login').' with this email: a one-time code verifies the address.',
            'Password login is '.(Identity::passwordsEnabled() ? 'enabled' : 'disabled').' (STARTER_AUTH_METHOD).',
        ]);

        return self::SUCCESS;
    }
}
