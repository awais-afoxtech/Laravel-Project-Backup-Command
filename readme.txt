Setup:
Add copy "BackupProjectDirectory.php" file to "LaraveProject/app/Console/Commands/"
Add "BackupProjectDirectory::class" in $commands array in "LaraveProject/app/Kernel.php"

Commands
php artisan backup:mysql --command=create
php artisan backup:mysql --command=restore