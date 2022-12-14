<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Create available import types
     *
     * @return void
     */
    public function run()
    {
        /*
        INSERT INTO `import_types` (`id`, `company_id`, `type`, `name`, `description`, `ftp_path`, `created_at`) VALUES
        (1, 'e0700753-8b88-c1b6-8cc9-1613b7154c7e', 'buehlers', 'Buehler\'s', 'Imports Disco and Active Item files', 'buehler/imports', '2020-08-05 18:41:40'),
        (2, '5b4619fc-bc76-989c-53ed-510d0be8c7c4', 'downtoearth', 'Down To Earth', 'Imports Inventory and Metrics', 'downtoearth/imports', '2020-08-05 18:41:40'),
        (3, '61ef52da-c0e1-11e7-a59b-080027c30a85', 'hansens', 'Hansen\'s Inventory', 'Imports Inventory updates by location comparison', 'hansens/imports/weekly', '2020-08-05 18:41:40'),
        (4, '61ef52da-c0e1-11e7-a59b-080027c30a85', 'hansens_metrics', 'Hansen\'s Metrics', 'Imports Metrics', 'hansens/imports', '2020-08-05 18:41:40'),
        (5, '0ba8c4a0-9e50-11e7-b25f-f23c917b0c87', 'lunds', 'Lunds Inventory', 'Imports Disco, Active Items, and Skip files', 'lunds/imports', '2020-08-05 18:41:40'),
        (6, 'd48c3be4-5102-1977-4c3c-2de77742dc1e', 'raleys', 'Raley\'s Inventory', 'Imports New, Disco, and Move files', 'raleys/imports', '2020-08-05 18:41:40'),
        (7, 'd48c3be4-5102-1977-4c3c-2de77742dc1e', 'raleys_metrics', 'Raley\'s Metrics', 'Imports Metrics', 'raleys/imports', '2020-08-05 18:41:40'),
        (8, '96bec4fe-098f-0e87-2563-11a36e6447ae', 'seg', 'SEG Inventory Update', 'Updates Inventory and Metrics', 'southeastern/imports', '2020-08-05 18:41:40'),
        (9, 'c3c9f97e-e095-1f19-0c5e-441da2520a9a', 'vallarta', 'Vallarta', 'Imports Inventory and Metrics files', 'vallarta/imports', '2020-08-05 18:41:40'),
        (10, '2719728a-a16e-ccdc-26f5-b0e9f1f23b6e', 'websters', 'Websters Inventory', 'Imports New Items', 'websters/imports', '2020-08-05 18:41:40'),
        (11, '2719728a-a16e-ccdc-26f5-b0e9f1f23b6e', 'websters_metrics', 'Websters Metrics', 'Imports Metrics', 'websters/imports', '2020-08-05 18:41:40'),
        (12, NULL, 'overlay_new', 'New Overlay', 'Copies dates for new items from within company', NULL, '2020-09-25 19:20:31'),
        (13, '76eb6116-0e73-4f25-90cb-bd65bc68cd09', 'hardings', 'Harding\'s Inventory', 'Imports Inventory updates by location comparison', 'hardings/imports', '2020-09-28 15:09:34'),
        (14, '1291eca3-984d-b36e-a04d-46290bea6a58', 'leprekon', 'Le-Pre-Kon Inventory and Metrics', 'Imports New, Disco, and Metrics files', 'urmstores/imports', '2020-10-02 19:26:13'),
        (15, 'c6ab761c-1e1f-3011-6740-053e1e81f7c7', 'foxbros', 'Fox Bros Inventory and Metrics', 'Import Update and Metrics files', 'foxbros/imports', '2020-10-07 17:18:47'),
        (16, 'c3c9f97e-e095-1f19-0c5e-441da2520a9a', 'vallarta_refresh', 'Refresh Vallarta Inventory', 'Requires Pilot_ files', 'vallarta/imports', '2020-10-30 02:32:25'),
        (17, NULL, 'overlay_oos', 'OOS Overlay', 'Copies dates for oos items from within company', NULL, '2020-10-29 16:18:35'),
        (18, '9a22701b-d7ae-aaac-1194-d583167f0ba4', 'bristol_metrics', 'Bristol Farms Metrics', 'Imports Metrics', 'bristol/imports', '2020-12-09 17:51:24'),
        (19, 'cd6314b2-f253-2a5a-35f7-ca0e92eb46b3', 'karns', 'Karns Metrics Import', 'Imports Products and Metrics', 'karns/imports', '2020-12-29 23:42:57'),
        (20, 'd48c3be4-5102-1977-4c3c-2de77742dc1e', 'raleys_refresh', 'Raley\'s Refresh', 'Updates Locations and Missing Inventory', 'raleys/imports', '2021-01-05 00:52:24'),
        (21, 'fc42b9dc-6d83-11e7-9139-f23c917b0c87', 'metcalfes_metrics', 'Metcalfe\'s Metrics', 'Imports Metrics', 'metcalfes/imports', '2021-01-06 19:10:40'),
	    (22, '96bec4fe-098f-0e87-2563-11a36e6447ae', 'seg_users', 'SEG User Update', 'Updates and deletes users', 'southeastern/imports', '2021-02-22 17:35:33'),
	    (23, '0ba8c4a0-9e50-11e7-b25f-f23c917b0c87', 'lunds_metrics', 'Lunds Metrics', 'Imports Metrics files', 'lunds/imports', '2021-03-02 22:45:23'),
	    (24, '3fb5b141-dd03-0555-39c5-0c0e37461825', 'janssens', 'Janssen\'s Inventory and Metrics', 'Imports new inventory and metrics', 'janssens/imports', '2021-03-19 18:50:59'),
    	(25, 'c3c9f97e-e095-1f19-0c5e-441da2520a9a', 'vallarta_baby', 'Refresh Vallarta Baby Dept Inventory', 'Requires Pilot_Baby_ files', 'vallarta/imports', '2021-04-05 15:06:25'),
	    (26, '9eb88cec-01d9-5353-61e5-613dd8a0ebec', 'caputos', 'Import Caputos Products', 'Requires local file', 'caputos/imports', '2021-04-12 14:10:59'),
	    (27, '61efcdfa-c0e1-11e7-af75-080027c30a85', 'leevers_metrics', 'Leevers Metrics', 'Requires local file', NULL, '2021-04-14 23:02:15'),
	    (28, '2291eca3-984d-b36e-a04d-462902ea6ax8', 'new_morning_market', 'New Morning Inventory Refresh', 'Updates inventory by comparison', 'newmorning/imports', '2021-06-04 03:41:43'),
	    (29, '6859ef83-7f11-05fe-0661-075be46276ec', 'price_chopper', 'Price Chopper Pilot', 'Imports products and metrics', 'pricechopper/imports', '2021-06-18 15:30:43'),
	    (30, NULL, 'overlay_notifications', 'Notification Dates Overlay', 'Copies dates for close dated and expiring items from within company', NULL, '2021-07-23 22:07:42'),
        (31, '9a22701b-d7ae-aaac-1194-d583167f0ba4', 'lazy_acres', 'Lazy Acres Products and Metrics', 'Imports Products and Metrics', NULL, '2021-09-01 17:26:57'),
	    (32, 'b32d41be-f52d-11eb-a2d3-42010a80001c', 'b_green', 'B Green Products & Metrics', 'Imports Products and Metrics', 'bgreen/imports', '2021-09-13 17:10:58'),
	    (33, '6489f200-452e-5b96-fd9e-c95c35eb7ad8', 'alaska', 'Alaska Products & Metrics', 'Imports Metrics', 'alaska/imports', '2021-11-02 20:45:34'),
	    (34, '6859ef83-7f11-05fe-0661-075be46276ec', 'price_chopper_compare', 'Price Chopper Inventory Refresh', 'Updates Inventory by comparison', 'pricechopper/imports', '2021-11-12 18:20:31'),
	    (35, 'e6fa66c6-6cbe-11ec-a378-42010a80001c', 'mayville', 'Mayville Pig Products & Metrics', 'Import Product and Metrics file', 'foxbros/imports', '2022-01-18 22:28:39'),
        (36, '9a22701b-d7ae-aaac-1194-d583167f0ba4', 'bristol_farms_compare', 'Bristol Farms Inventory Refresh', 'Updates Inventory by comparison', 'imports/bristolfarms', '2022-02-02 18:20:31'),
    	(37, '28e860a6-6ff8-11ec-8850-080027d17c37', 'sprouts', 'Sprouts Pilot Import', 'Imports Products', 'sprouts/imports', '2022-02-01 21:05:32'),
    	(38, 'e6fa66c6-6cbe-11ec-a378-42010a80001c', 'mayville_compare', 'Mayville Pig Inventory Comparison', 'Import Update and Metrics files', 'foxbros/imports', '2022-02-23 20:15:19'),
        (39, '6489f200-452e-5b96-fd9e-c95c35eb7ad8', 'alaska_compare', 'Alaska Inventory Compare', 'Inventory Refresh', 'alaska/imports', '2022-03-25 23:19:32')
        (40, '28e860a6-6ff8-11ec-8850-080027d17c37', 'sprouts_compare', 'Sprouts Weekly Inventory Comparison Import', 'Sprouts Weekly Inventory Comparison Import', 'sprouts/imports', '2022-02-01 21:05:32'),
        */
    }
}
