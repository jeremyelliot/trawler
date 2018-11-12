#!/usr/bin/php
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Trawler\\', __DIR__ . 'Trawler');

use \Trawler\Service\HostsService;
use \Trawler\Service\PagesService;
use \Trawler\PageFetcher;

/**
 * Trawler page fetcher
 */
 // get CLI options (args)

// read config
include 'config.php';

// prepare service options

// instantiate service and runner objects
$pagesService = new PagesService(
    $config['db']['mongodb'] + ['collection' => ($config['db']['pagesCollection'])],
    new HostsService($config['db']['mongodb'] + ['collection' => $config['db']['hostsCollection']]),
    $config['fetch']['pagesService']
);

echo "STATUS                 COUNT\n";
echo "----------------------------\n";
$totalUrls = 0;
foreach ($pagesService->getStatusCounts() as $status) {
    $totals[(empty($status->_id)) ? 'new' : $status->_id] = $status->count;
    $totalUrls += $status->count;
    printf("%-16s%12d\n", (empty($status->_id)) ? 'new' : $status->_id, $status->count);
}
$totals['totalUrls'] = $totalUrls;
printf("%-16s%12d\n", 'Total URLs', $totalUrls);
echo PHP_EOL;
?>
