#!/usr/bin/php
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Trawler\\', __DIR__ . 'Trawler');

use \Trawler\Service\HostsService;
use \Trawler\Service\PagesService;
use \Trawler\ScraperRunner;
use \Trawler\Scraper\UrlScraper;
use \Trawler\Scraper\MicrodataScraper;
use \Trawler\Repository\MicrodataRepository;

// declare(ticks = 1); // to enable pcntl_signal()
pcntl_async_signals(true);


$commandOptions = getopt('v', ['init']);
$trawlerInitialise = isset($commandOptions['init']);
/**
 * Trawler scraper runner
 */
 // get CLI options (args)

// read config
include 'config.php';

// instantiate service and runner objects
$pagesService = new PagesService(
    $config['db']['mongodb'] + ['collection' => $config['db']['pagesCollection']],
    new HostsService(
        $config['db']['mongodb'] + ['collection' => $config['db']['hostsCollection']],
        $config['scrape']['hostsService']
    ),
    $config['scrape']['pagesService']
);

$scraperRunner = new ScraperRunner($pagesService, function ($exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
});
$scraperRunner
    ->addScraper(new UrlScraper($pagesService, $config['scrape']['urlFilters']))
    ->addScraper(
        new MicrodataScraper(
            new MicrodataRepository(
                $config['db']['mongodb'] + ['collection' => $config['db']['microdataCollection']]
            )
        )
    );

$stopSignal = false;
pcntl_signal(SIGINT, function ($signo, $siginfo=null) {
    global $stopSignal;
    $stopSignal = true;
});
// start the generator...
foreach ($scraperRunner->loop($stopSignal) as $message) {
    if (!empty($message)) {
        echo $message . PHP_EOL;
    }
}
?>
