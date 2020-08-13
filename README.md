# DCP2 Import Manager

This project handles the running of automated imports for Date Check Pro.  
It requires a connection to the DCP2 API and the DCP2 Admin database.

## Local Project Setup
1. Copy `.env.example` to `.env` and update credentials
2. Run `composer install`

### Add a New Import
1. Create app/Imports/Import___ class implementing ImportInterface
2. Add row to import_types table mapping key to company_id
3. Add key to 

### Manually Run Import
php artisan dcp:do-import key

### Automatically Run Imports
add laravel cron job

### Clear Failed Import (in case of runtime error)
php artisan dcp:clear
