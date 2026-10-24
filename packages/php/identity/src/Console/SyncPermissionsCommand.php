<?php

namespace Starter\Identity\Console;

use Illuminate\Console\Command;
use Starter\Identity\Authorization\PermissionSynchronizer;

class SyncPermissionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:permissions
        {--prune : Delete permissions that are no longer in the catalog}';

    /**
     * @var string
     */
    protected $description = 'Sync the permission catalog and the system roles (owner, admin, editor, reader)';

    public function handle(PermissionSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync((bool) $this->option('prune'));

        $this->components->info(sprintf(
            'Permissions synced: %d created, %d removed.',
            count($result['created']),
            count($result['removed']),
        ));

        return self::SUCCESS;
    }
}
