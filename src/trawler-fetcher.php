#!/usr/bin/php
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Trawler\\', __DIR__ . 'Trawler');

use \Trawler\Service\HostsService;
use \Trawler\Service\PagesService;
use \Trawler\PageFetcher;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;

// declare(ticks = 1);
pcntl_async_signals(true);


$commandOptions = getopt('v', ['init']);
$trawlerInitialise = isset($commandOptions['init']);


/**
 * Trawler page fetcher
 */
 // get CLI options (args)

// read config
include 'config.php';

// instantiate service and runner objects
$pagesService = new PagesService(
    $config['db']['mongodb'] + ['collection' => ($config['db']['pagesCollection'])],
    new HostsService(
        $config['db']['mongodb'] + ['collection' => $config['db']['hostsCollection']],
        $config['fetch']['hostsService']
    ),
    $config['fetch']['pagesService']
);

$fetcher = new PageFetcher(
    $pagesService,
    null,
    // function ($exception) {
    //     fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    // },
    $config['fetch']['http']
);
$fetcher
    // ->setVerboseLogger(new Logger('verbose', [new StreamHandler(STDOUT)]))
    ->setContentFilter(function ($content) {
        return \mb_ereg_replace('\s\s+', ' ', $content, 'msl');
    });

$stopSignal = false;
pcntl_signal(SIGINT, function ($signo, $siginfo=null) {
    global $stopSignal;
    $stopSignal = true;
});
// start the generator...
foreach ($fetcher->loop($stopSignal) as $message) {
    if (!empty($message)) {
        echo $message . PHP_EOL;
    }
}
?>
