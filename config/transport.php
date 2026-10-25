<?php

return [
    'database_enabled' => env('TRANSPORT_DATABASE_ENABLED', false),
    'sample_data' => env('TRANSPORT_SAMPLE_DATA', true),
    'legacy_timezone' => 'Asia/Manila',
    'jdbc_jar' => env('MYSQL_JDBC_JAR', base_path('mysql-connector-j-26.7.0.jar')),
    'java_binary' => env('JAVA_BINARY', 'java'),
];
