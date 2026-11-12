<?php

return [
    // The database connection the MySQL analytics store uses. Point ANALYTICS_DB_CONNECTION at a separate database
    // (e.g. a DigitalOcean Managed MySQL instance) to keep reporting off the main database.
    'connection' => env('ANALYTICS_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    // Rows read per source per run.
    'batch' => (int) env('ANALYTICS_BATCH', 5000),
];
