<?php

namespace Spatie\Backup\Tasks\Restore;

use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;

class RestoreBackupFactory
{
    public static function createByBackup(BackupDestination $backupDestination, Backup $backup): RestoreBackup
    {
        return new RestoreBackup($backupDestination, $backup);
    }
}
