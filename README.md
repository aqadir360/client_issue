# DCP2 Import Manager

This project handles the running of automated imports for Date Check Pro.  
It requires a connection to the DCP2 API and the DCP2 Admin database.

## Local Project Setup
1. Copy `.env.example` to `.env` and update credentials
2. Set DB_ADMIN_DATABASE to DCP2 Admin project database
3. Run `composer install`
4. Run `php artisan migrate`

### Add a New Import
1. Create app/Imports/Import___ class implementing ImportInterface
2. Add row to import_types table mapping key to company_id
3. Add key to ImportFactory class mapping
4. Copy insert row into the DatabaseSeeder class
5. Import will now be available for scheduling in the Admin

### Manually Run Import
php artisan dcp:do-import key

### Automatically Run Imports
add laravel cron job

### Debug Mode
To turn on debug mode, set .env SCRAPER_DEBUG_MODE = 'debug'

While in debug mode:
- Reads a maximum of 1000 lines from each file
- No write calls are made to the API
- No metrics are written to the local database
- Downloaded files are not deleted
- Skips writing updated lastRun value to allow multiple runs
"# client_issue" 
