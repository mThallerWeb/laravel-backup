<?php

namespace Spatie\Backup\Commands;

use Illuminate\Support\Collection;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\Helpers\Format;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Spatie\Backup\Tasks\Restore\RestoreBackup;
use Spatie\Backup\Tasks\Restore\RestoreBackupFactory;

class RestoreCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'backup:restore';

    /** @var string */
    protected $description = 'Restores a backup.';

    public function handle()
    {
        $statuses = BackupDestinationStatusFactory::createForMonitorConfig(config('laravel-backup.monitorBackups'));
        $backupsToSelectFrom = $statuses->filter(function(BackupDestinationStatus $backupDestinationStatus) {
            return ($backupDestinationStatus->isHealthy() && $backupDestinationStatus->isReachable());
        });
        $this->info('Available Backups for restore');
        $this->displayBackupDestinationOverview($backupsToSelectFrom);

        $backupsForSelect = $backupsToSelectFrom->map(function(BackupDestinationStatus $backupDestinationStatus) {
            return $backupDestinationStatus->backupName();
        })->toArray();

        $backupNameToRestore = $this->anticipate('Which backup do you want to restore?', $backupsForSelect, array_first($backupsForSelect));
        /** @var BackupDestinationStatus $backupToRestore */
        $backupToRestore = $backupsToSelectFrom->filter(function(BackupDestinationStatus $backupDestinationStatus) use ($backupNameToRestore) {
            return ($backupNameToRestore == $backupDestinationStatus->backupName());
        })->first();

        if(!$backupToRestore) {
            $this->error('Invalid backup name for restore given');
            return;
        }

        $backupDestination = $backupToRestore->backupDestination();

        $availableBackupFiles = $backupDestination->backups();
        $this->displayBackupOverview($availableBackupFiles);

        $availableBackupFilesForChoice = $availableBackupFiles->map(function(Backup $backup) {
            return $backup->date()->format('Y-m-d-H-i-s');
        })->toArray();


        $backupDateToRestore = $this->anticipate('Which backupfile do you want to restore?', $availableBackupFilesForChoice, array_first($availableBackupFilesForChoice));

        /** @var Backup $backupFileToRestore */
        $backupFileToRestore = $availableBackupFiles->filter(function(Backup $backup) use ($backupDateToRestore){
            return ($backup->date()->format('Y-m-d-H-i-s') == $backupDateToRestore);
        })->first();

        if(!$backupFileToRestore) {
            $this->error('Invalid backup file selected');
            return;
        }

        if(app()->environment() == 'production') {
            $this->warn('!!! Application in production, can not restore backup !!!');
            return;
        }

        $restoreTask = RestoreBackupFactory::createByBackup($backupDestination, $backupFileToRestore);

        $this->info('Validating import');
        if(!$restoreTask->validate()) {
            $this->error('Can not validate backup cause of the following reasons');
            $this->printRestoreErrors($restoreTask->getErrors());
            return;
        }

        if($restoreTask->restore()) {
           $this->info('Restored data successfully');
        } else {
            $this->error('Error while restoring the data. Please check your application, maybe it\'s broken now');
            $this->printRestoreErrors($restoreTask->getErrors());
        }
    }

    protected function displayBackupOverview(Collection $backups)
    {
        $headers = ['Filename', 'Date', 'Used storage'];

        $rows = $backups->map(function (Backup $backup) {
            return $this->convertBackupRow($backup);
        });

        $this->table($headers, $rows);
    }

    public function convertBackupRow(Backup $backup): array
    {
        $row = [
            basename($backup->path()),
            $backup->date()->format('Y-m-d H:i:s'),
            'usedStorage' => Format::humanReadableSize($backup->size()),
        ];

        return $row;
    }

    protected function displayBackupDestinationOverview(Collection $backupDestinationStatuses)
    {
        $headers = ['Name', 'Disk', 'Reachable', 'Healthy', '# of backups', 'Newest backup', 'Used storage'];

        $rows = $backupDestinationStatuses->map(function (BackupDestinationStatus $backupDestinationStatus) {
            return $this->convertBackupDestinationRow($backupDestinationStatus);
        });

        $this->table($headers, $rows);
    }

    public function convertBackupDestinationRow(BackupDestinationStatus $backupDestinationStatus): array
    {
        $row = [
            $backupDestinationStatus->backupName(),
            $backupDestinationStatus->diskName(),
            Format::emoji($backupDestinationStatus->isReachable()),
            Format::emoji($backupDestinationStatus->isHealthy()),
            'amount' => $backupDestinationStatus->amountOfBackups(),
            'newest' => $backupDestinationStatus->dateOfNewestBackup()
                ? Format::ageInDays($backupDestinationStatus->dateOfNewestBackup())
                : 'No backups present',
            'usedStorage' => $backupDestinationStatus->humanReadableUsedStorage(),
        ];

        if (! $backupDestinationStatus->isReachable()) {
            foreach (['amount', 'newest', 'usedStorage'] as $propertyName) {
                $row[$propertyName] = '/';
            }
        }

        $row = $this->applyStylingToRow($row, $backupDestinationStatus);

        return $row;
    }

    protected function applyStylingToRow(array $row, BackupDestinationStatus $backupDestinationStatus): array
    {
        if ($backupDestinationStatus->newestBackupIsTooOld() || (! $backupDestinationStatus->dateOfNewestBackup())) {
            $row['newest'] = "<error>{$row['newest']}</error>";
        }

        if ($backupDestinationStatus->usesTooMuchStorage()) {
            $row['usedStorage'] = "<error>{$row['usedStorage']} </error>";
        }

        return $row;
    }

    private function printRestoreErrors($errors)
    {
        foreach($errors as $error) {
            $this->error($error);
        }
    }
}
