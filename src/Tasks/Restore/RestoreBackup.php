<?php

namespace Spatie\Backup\Tasks\Restore;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Backup\TemporaryDirectory;
use DB, ZipArchive;

class RestoreBackup
{

    /**
     * @var TemporaryDirectory
     */
    protected $temporaryDirectory;

    /**
     * @var string
     */
    protected $extractDirectory = 'extract';

    /**
     * @var array
     */
    protected $includePathsToRestore = [];

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var bool
     */
    protected $validatedImport;

    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * @var array
     */
    protected $includeDatabasesToRestore = [];

    /**
     * @var Backup
     */
    private $backup;
    /**
     * @var BackupDestination
     */
    private $backupDestination;
    /**
     * @var bool
     */
    private $restoreFiles;
    /**
     * @var bool
     */
    private $restoreDatabases;

    /**
     * RestoreBackup constructor.
     * @param BackupDestination $backupDestination
     * @param Backup $backup
     * @param bool $restoreFiles
     * @param bool $restoreDatabases
     */
    public function __construct(
        BackupDestination $backupDestination,
        Backup $backup,
        $restoreFiles = true,
        $restoreDatabases = true
    ) {
        $this->backup = $backup;
        $this->backupDestination = $backupDestination;
        $this->createTmpDir();
        $this->config = $this->getConfigForCurrentBackup();
        $this->validatedImport = false;
        $this->restoreFiles = $restoreFiles;
        $this->restoreDatabases = $restoreDatabases;
        $this->fileSystem = new Filesystem();
    }

    /**
     *
     */
    public function createTmpDir()
    {
        $this->temporaryDirectory = TemporaryDirectory::create();
    }

    /**
     *
     */
    public function getConfigForCurrentBackup()
    {
        $configurations = config('laravel-backup.backup');
        if (isset($configurations['name'])) {
            $configurations = [$configurations];
        }

        foreach ($configurations as $config) {
            if (preg_replace('/[^a-zA-Z0-9.]/', '-', $config['name']) == $this->backupDestination->backupName()) {
                return $config;
            }
        }

        return [];
    }

    /**
     * @throws Exception
     */
    public function validate()
    {
        $backupLocalPath = $this->moveBackupToLocal();
        $zipArchive = $this->createZipObject($backupLocalPath);

        if (!$this->extractFiles($zipArchive)) {
            throw new Exception('Could not extract files from directory');
        }

        if ($this->restoreFiles) {
            foreach ($this->config['source']['files']['include'] as $fileIncludePath) {
                $currentPath = $this->temporaryDirectory->path() . '/' . $this->extractDirectory . '/' . ltrim($fileIncludePath,
                        '/');
                if (file_exists($currentPath)) {
                    $this->includePathsToRestore[] = [
                        'originalPath' => $fileIncludePath,
                        'tmpRestorePath' => $currentPath
                    ];
                } else {
                    $this->errors[] = 'Can not find backup for directory "' . $fileIncludePath . '"';
                }
            }
        }

        if ($this->restoreDatabases) {
            foreach ($this->config['source']['databases'] as $databaseToRestore) {
                $databaseConnection = config('database.connections.' . $databaseToRestore);
                $currentPath = $this->temporaryDirectory->path() . '/' . $this->extractDirectory . '/db-dumps/' . $databaseConnection['database'] . '.sql';
                if (file_exists($currentPath)) {
                    $this->includeDatabasesToRestore[] = [
                        'file' => $currentPath,
                        'connection' => $databaseToRestore,
                        'connection_config' => $databaseConnection
                    ];
                } else {
                    $this->errors[] = 'Can not find backup for database "' . $databaseToRestore . '"';
                }
            }
        }

        if (!empty($this->errors)) {
            $this->cleanUp();
            return false;
        } else {
            $this->validatedImport = true;
            return true;
        }
    }

    public function restore()
    {
        if (!$this->validatedImport) {
            $this->errors[] = 'Backup was not validated';
        }

        if ($this->restoreFiles) {
            try {
                if (!$this->doFileRestore()) {
                    return false;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        if ($this->restoreDatabases) {
            try {
                if (!$this->doDatabaseRestore()) {
                    return false;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        $this->cleanUp();

        return true;
    }

    /**
     * @param ZipArchive $zipArchive
     * @return bool
     */
    public function extractFiles(ZipArchive $zipArchive)
    {
        $pathToExtract = $this->temporaryDirectory->path($this->extractDirectory);

        return $zipArchive->extractTo($pathToExtract);
    }

    /**
     * @return string
     */
    protected function moveBackupToLocal()
    {
        $tempBackupFilePath = $this->temporaryDirectory->path(basename($this->backup->path()));
        $backupFile = $this->backup->getDisk()->get($this->backup->path());
        file_put_contents($tempBackupFilePath, $backupFile);

        return $tempBackupFilePath;
    }

    /**
     * @param $filePath
     * @return ZipArchive
     */
    protected function createZipObject($filePath)
    {
        $zipArchive = new ZipArchive();
        $zipArchive->open($filePath);

        return $zipArchive;
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Does some cleanup actions on the end of the restore execution
     */
    private function cleanUp()
    {
        $this->temporaryDirectory->delete();
    }

    /**
     * Restores all the files in the backup
     *
     * @return bool
     */
    private function doFileRestore()
    {
        foreach ($this->includePathsToRestore as $restorePath) {
            if (!$this->fileSystem->moveDirectory($restorePath['tmpRestorePath'], $restorePath['originalPath'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    private function doDatabaseRestore()
    {
        foreach ($this->includeDatabasesToRestore as $restoreInformation) {
            /** @var Connection $dbConnection */
            $dbConnection = DB::connection($restoreInformation['connection']);

            $tablesToDelete = $dbConnection->select('show tables');
            $dbConnection->statement('SET foreign_key_checks = 0');
            foreach ($tablesToDelete as $tableToDelete) {
                $dbConnection->statement('DROP table ' . $tableToDelete->{'Tables_in_' . $restoreInformation['connection_config']['database']});
            }

            $contentForMysql = 'USE ' . $restoreInformation['connection_config']['database'] . ';' . PHP_EOL;
            $contentForMysql .= file_get_contents($restoreInformation['file']);

            $dbConnection->unprepared($contentForMysql);
        }

        return true;
    }
}
