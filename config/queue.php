<?php

$sqs_jobs_prefix = env('SQS_PREFIX', 'https://sqs.ap-southeast-1.amazonaws.com/991213066046');
$sqs_jobs_queue = env('SQS_JOBS_QUEUE', 'jobs');

if ($queueUrl = getenv('QUEUE_URL_JOBS')) {
    $url = parse_url($queueUrl);

    $sqs_jobs_prefix = $url['scheme'] . '://' . $url['host'] . dirname($url['path']);
    $sqs_jobs_queue = basename($url['path']);
}
$sqs_events_prefix = env('SQS_PREFIX', 'https://sqs.ap-southeast-1.amazonaws.com/991213066046');
$sqs_events_queue = env('SQS_EVENTS_QUEUE', 'events');

if ($queueUrl = getenv('QUEUE_URL_EVENTS')) {
    $url = parse_url($queueUrl);

    $sqs_events_prefix = $url['scheme'] . '://' . $url['host'] . dirname($url['path']);
    $sqs_events_queue = basename($url['path']);
}

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */
   
    'sqs' => [
        'driver' => 'sqs',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'prefix' => $sqs_jobs_prefix,
        'queue' => $sqs_jobs_queue,
        'suffix' => env('SQS_SUFFIX'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
    ],

    'events' => [
        'driver' => 'sqs',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'prefix' => $sqs_events_prefix,
        'queue' => $sqs_events_queue,
        'suffix' => env('SQS_SUFFIX'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
    ],

    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
];
