<?php
return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds',
    ],

    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_database' => 'development',

        'testing' => [
            'adapter' => 'sqlite',
            'name' => ':memory:',
            'memory' => true,
        ],

        'development' => [
            'adapter' => 'mysql',
            'db_write' => [
                'host' => 'localhost',
                'name' => 'DateCheckPro',
            ],
            'db_read' => [
                'host' => 'localhost',
                'name' => 'DateCheckPro',
            ],
            'sync_write' => [
                'host' => 'localhost',
                'name' => 'dcp2sync',
            ],
            'sync_read' => [
                'host' => 'localhost',
                'name' => 'dcp2sync',
            ],
            'name' => 'DateCheckPro',
            'host' => 'localhost',
            'user' => 'homestead',
            'pass' => 'secret',
            'port' => 3306,
            'charset' => 'utf8',
        ],
    ],

    'version_order' => 'creation',

];
