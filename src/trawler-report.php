#!/usr/bin/php
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Trawler\\', __DIR__ . 'Trawler');

use \Trawler\Service\HostsService;
use \Trawler\Service\PagesService;
use \Trawler\PageFetcher;
use Trawler\Repository\MicrodataRepository;

/**
 * Trawler page fetcher
 */
 // get CLI options (args)

// read config
include 'config.php';

// prepare service options

// instantiate service objects
$pagesService = new PagesService(
    $config['db']['mongodb'] + ['collection' => ($config['db']['pagesCollection'])],
    new HostsService($config['db']['mongodb'] + ['collection' => $config['db']['hostsCollection']]),
    $config['fetch']['pagesService']
);

$microdataRepo = new MicrodataRepository(
    $config['db']['mongodb'] + ['collection' => $config['db']['microdataCollection']]
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
echo "Microdata Types:\n";
echo "COUNT     TYPE\n";
echo "-------------------------------------------------\n";
foreach ($microdataRepo->getItemTypeCounts() as $itemType) {
    printf("%9d %-60s\n", $itemType->count, implode(' ', $itemType->_id->getArrayCopy()));
}
?>
