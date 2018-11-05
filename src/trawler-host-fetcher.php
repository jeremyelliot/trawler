#!/usr/bin/php
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Trawler\\', __DIR__ . '/Trawler');

use \Trawler\Service\HostsService;
use \Trawler\HostFetcher;

// declare(ticks = 1);
pcntl_async_signals(true);


/**
 * Trawler host fetcher
 */
 // get CLI options (args)

// read config
include 'config.php';

// prepare service options

// instantiate service and runner objects
$hostsService = new HostsService(
    $config['db']['mongodb'] + ['collection' => $config['db']['hostsCollection']],
    $config['fetch']['hostsService']
);
$fetcher = new HostFetcher($hostsService, function ($exception) {
    fwrite(STDERR, $exception->getMessage());
});
$fetcher->setMessageListener(function ($message) {
    echo $message;
});

$stopSignal = false;
pcntl_signal(SIGINT, function ($signo, $siginfo=null) {
    global $stopSignal;
    $stopSignal = true;
});
// start the generator...
foreach ($fetcher->loop($stopSignal) as $message) {
    echo $message . PHP_EOL;
}
?>
