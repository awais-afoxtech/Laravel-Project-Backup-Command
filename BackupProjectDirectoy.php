<?php

namespace App\Console\Commands;

use Error;
use Exception;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Helper\ProgressBar;
use ZipArchive;

class BackupProjectDirectory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:project {--command= : <create|restore> command to execute} {--backupFileName= : provide name of backupFileName}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command can backup up the backend folder and can restore the any given backup.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Ask for command need to run backup,restore
        $command = $this->option('command') ?? $this->ask('Enter the name of command needs to be run. Options:[create, restore])');

        if (!in_array($command, ['create', 'restore'], true)) {
            $this->error("Invalid option selected!!");
            return;
        }

        switch ($command) {
            case 'create':
                $this->makeBackupArchive();
                break;

            case 'restore':
                $this->restoreBackupArchive();
                break;

            default:
                $this->error("Invalid Option !!");
                break;
        }

        return 0;
    }

    private function makeBackupArchive()
    {
        set_time_limit(0);

        $this->info("Backup Process started.");

        try {
            // Get the path to the project directory
            $projectPath = base_path();

            // Set the name of the backup file
            $backupFileName = 'backend_' . date('Y-m-d_H-i-s') . '.zip';

            // Set the path to the backup directory
            $backupDirectory = dirname($projectPath) . '/backend-backup';

            // Create the backup directory if it doesn't exist
            if (!file_exists($backupDirectory)) {
                mkdir($backupDirectory);
            }

            // Create a zip archive of the project directory
            $zip = new ZipArchive();
            $zip->open($backupDirectory . '/' . $backupFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($projectPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            // Get the total number of files for progress tracking
            $totalFiles = iterator_count($files);

            // Initialize the ProgressBar
            $this->info("\nArchiving files");
            $progressBar = new ProgressBar($this->output, $totalFiles);
            $progressBar->start();

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($projectPath) + 1);

                    // Exclude vendors or node_modules folders from any child directory
                    // if (
                    //     strpos($relativePath, '/vendor/') === false &&
                    //     strpos($relativePath, '/node_modules/') === false &&
                    //     !preg_match('/\/vendor\/.+\/node_modules\//', $relativePath)
                    // ) {
                    $zip->addFile($filePath, $relativePath);
                    // }

                    // Update the ProgressBar
                    $progressBar->advance();
                }
            }
            $zip->close();

            // Complete the ProgressBar
            $progressBar->finish();
            $this->info("\nArchiving files done");

            $this->info("\nProject directory backup created at " . $backupDirectory . '/' . $backupFileName);

            $this->info("\nLooking for backups older then 3 days.");
            // Delete backups older than 3 days
            $maxBackupAge = 3 * 24 * 60 * 60; // 3 days in seconds
            if ($handle = opendir($backupDirectory)) {
                while (false !== ($entry = readdir($handle))) {
                    $file = $backupDirectory . '/' . $entry;
                    if (is_file($file) && time() - filemtime($file) > $maxBackupAge) {
                        if (strpos($file, $backupDirectory) === 0) {
                            unlink($file);
                            $this->info("\nDeleted backup: $file");
                        }
                    }
                }
                closedir($handle);
            }

            // Output a success message
            $this->info("\nBackup Process finished.");

        } catch (Exception | Error $e) {
            $this->info($e->getMessage());
        }
    }

    private function restoreBackupArchive()
    {
        set_time_limit(0);

        try {
            // Get the path to the project directory
            $projectPath = base_path();

            // Ask for the name of the backup file to restore
            $backupFileName = $this->option('backupFileName') ?? $this->ask('Enter the name of the backup file to restore (e.g. backend_2023-04-10_12-34-56.zip)');

            if (!$backupFileName) {
                $this->error("Backup File Name option is required.");
                return;
            }

            // Set the path to the backup file
            $backupFilePath = dirname($projectPath) . '/backend-backup/' . $backupFileName;

            // Check if the backup file exists
            if (!file_exists($backupFilePath)) {
                $this->error('Backup file not found at ' . $backupFilePath);
                return;
            }

            // Create a zip archive object from the backup file
            $zip = new ZipArchive();
            if ($zip->open($backupFilePath) !== true) {
                $this->error('Failed to open backup file at ' . $backupFilePath);
                return;
            }

            // Extract the contents of the backup file to the project directory with progress tracking
            $totalFiles = $zip->numFiles;

            // Initialize the ProgressBar
            $this->info("\nExtracting files");
            $progressBar = new ProgressBar($this->output, $totalFiles);
            $progressBar->start();

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                $entryStream = $zip->getStream($entryName);

                // Check if the entry is a directory
                if (substr($entryName, -1) === '/') {
                    // Create the directory if it doesn't exist
                    $entryPath = $projectPath . '/' . $entryName;
                    if (!is_dir($entryPath)) {
                        mkdir($entryPath, 0755, true);
                    }
                } else {
                    // Extract the file contents to a new file
                    $entryPath = $projectPath . '/' . $entryName;
                    $entryFile = fopen($entryPath, 'wb');
                    if ($entryFile === false) {
                        $this->error('Failed to create file ' . $entryPath);
                        return;
                    }

                    // Read the contents of the entry from the stream and write to the file
                    while (!feof($entryStream)) {
                        fwrite($entryFile, fread($entryStream, 8192));
                    }
                    fclose($entryFile);
                }

                // Update the ProgressBar
                $progressBar->advance();

            }

            $zip->close();

            // Complete the ProgressBar
            $progressBar->finish();
            $this->info("\nExtracting files done");

            // Output a success message
            $this->info("\nDirectory backup restored from " . $backupFilePath);

        } catch (Exception | Error $e) {
            $this->info($e->getMessage());
        }
    }

}
