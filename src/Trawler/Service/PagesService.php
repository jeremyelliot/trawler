<?php

namespace Trawler\Service;

use \MongoDB\Client;
use \MongoDB\Collection;
use \MongoDB\Driver\Cursor;
use \MongoDB\Driver\WriteConcern;
use \Trawler\Service\HostsService;
use \JeremyElliot\UrlHelper;
use \vipnytt\RobotsTxtParser\TxtClient;
use \vipnytt\RobotsTxtParser\Client\Directives\UserAgentClient;
use \Trawler\Service\Interfaces\PagesServiceInterface;
use \Trawler\Service\Interfaces\HostsServiceInterface;

class PagesService implements PagesServiceInterface
{
    const STATUS_FETCHING = 'fetching';
    const STATUS_FETCHED = 'fetched';
    const STATUS_SCRAPING = 'scraping';
    const STATUS_SCRAPED = 'scraped';
    const STATUS_BLOCKED = 'blocked';
    const USER_AGENT_NAME = 'Trawler/1.0';

    /** @var Collection */
    private $collection;

    /**
     * @var HostsService
     */
    private $hostsService;

    /**
     * urls know to exist already, to reduce unnecessary updates (array)
     * @var array $knownUrls
     */
    private $knownUrls = [];

    /**
     * Maximum size of $knownUrls array (int)
     * @var int
     */
    private $maxKnownUrls = 10000;

    /**
     *
     *
     * @var bool
     */
    private $preloadKnownUrls = false;


    /**
     * array of hostnames, each having an array urls, for example: [
     *      'http://www.example.co.nz' => [
     *          'http://www.example.co.nz/index.html',
     *          'http://www.example.co.nz/about.html'
     *      ],
     *      'http://www.example.com' => [
     *          'http://www.example.com/index.html',
     *          'http://www.example.co.nz/about.html'
     *      ]
     *  ]
     * @var array<object> $urlsBatches
     */
    private $urlsBatches = [];

    private $batchSize = 100;

    private $hostsCache = [];
    private $hostsCacheIndex = 0;
    /**
     * milliseconds between cycles of hosts cache
     * @var int $hostsCrawlDelay
     */
    private $crawlDelay = 20 * 1000;

    /**
     * milliseconds at time of last hostsCycle
     * @var int $hostsCycle
     */
    private $hostsCycleTime;

    /** @var array $pendingWrites */
    private $pendingBulkWrites = [];
    private $maxPendingBulkWrites = 10;

    /**
     * Takes an array of options for connecting to the database
     *
     * $databseOptions example: [
     *      'host' => '127.0.0.1',
     *      'port' => 27017,
     *      'database' => 'trawler',
     *      'username' => 'trawler',
     *      'password' => 'somepassword',
     *      'collection' => 'trawlerPages']
     *
     * @param array $databseOptions options for connecting to the database
     * @param array HostsService
     */
    public function __construct(array $databseOptions, HostsServiceInterface $hostsService, array $serviceOptions=null)
    {
        extract($databseOptions);
        $client = new Client("mongodb://$username@$host:$port/$database", [
            'password' => urlencode($password)
        ]);
        $this->collection = $client->$database->$collection;
        $this->hostsService = $hostsService;
        foreach (['batchSize', 'maxPendingBulkWrites', 'maxKnownUrls', 'preloadKnownUrls', 'crawlDelay'] as $key) {
            if (isset($serviceOptions[$key])) {
                $this->$key = $serviceOptions[$key];
            }
        }
        if ($this->preloadKnownUrls) {
            $this->loadKnownUrls();
        }
        $this->hostsCycleTime = (int) (microtime(true) * 1000);
    }

    /**
     * Preloads the knownUrls cache array
     */
    private function loadKnownUrls()
    {
        $this->knownUrls = array_map(function ($page) {
            return $page->url;
        }, $this->collection->find([], ['limit' => (int) ($this->maxKnownUrls / 2)])->toArray());
    }

    /**
     * Get the next URL of the next page to be fetched
     *
     * @return string URL
     */
    public function getNextUrl() : string
    {
        do {
            // get the next host to be crawled
            $host = $this->hostsService->getNextHostToCrawl();
            // if there is no next host, nothing more can be done
            if (empty($host)) {
                return '';
            }
            do {
                // get the next url for selected host
                $url = $this->getNextUrlForHost($host->host);
                // if there is no next url for this host, return to outer loop to get next host
                if (empty($url)) {
                    break;
                }
                $isCrawlingAllowed = $this->isCrawlingAllowed($host->host, $host->robots->txt, $url);
                if (!$isCrawlingAllowed) {
                    $this->setUrlBlocked("$url");
                    $url = '';
                }
            } while (!$isCrawlingAllowed);
        } while (empty($url));
        return $url;
    }

    /**
     * Returns next host for fetching.
     *
     * Host is taken from cache array. This service will cycle through the cached hosts.
     * A host will be removed from the cache when there are no more urls for that host.
     * If there are fewer than (batchSize) hosts in the cache, a new one will be fetched into the cache.
     *
     * @return object
     */
    private function getNextHost() : ?object
    {
        if (count($this->hostsCache) < $this->batchSize) {
            $host = $this->hostsService->getNextHostToCrawl();
            $this->hostsCache[$host->host] = $host;
            return $host;
        }
        $this->hostsCacheIndex = $this->hostsCacheIndex++ % count($this->hostsCache);
        if ($this->hostsCacheIndex === (count($this->hostsCache) - 1)) {
            usleep($this->crawlDelay * 1000);
        }
        return $this->hostsCache[$this->hostsCacheIndex];
    }

    /**
     * Returns the next available url for the given host.
     *
     * Urls are taken from a cache array. If the cache has no url for the host,
     * a new batch of urls will be fetched.
     *
     * @param string $host name of host
     * @return string url
     */
    private function getNextUrlForHost(string $host) : ?string
    {
        if (empty($this->urlsBatches[$host])) {
            $this->urlsBatches[$host] = array_map(function ($url) {
                return $url->url;
            }, $this->getNextUrlsBatch($host));
        }
        return array_pop($this->urlsBatches[$host]);
    }

    /**
     * fetches and returns a batch of urls
     *
     * @param string $hostname name of the host
     * @return array url objects
     */
    private function getNextUrlsBatch(string $hostname) : array
    {
        $urls = $this->collection->find(
            [
                'status' => ['$exists' => false],
                'host' => $hostname
            ],
            ['limit' => $this->batchSize]
        )->toArray();
        // if there are no more URLs for the host, remove them from the caches
        if (empty($urls)) {
            unset($this->urlsBatches[$hostname]);
            unset($this->hostsCache[$hostname]);
            return [];
        }
        $this->collection->bulkWrite(
            array_map(function ($url) {
                return ['updateOne' => [
                        ['_id' => $url->_id],
                        ['$set' => ['status' => self::STATUS_FETCHING]]
                    ]];
            }, $urls),
            ['ordered' => false, 'writeConcern' => new WriteConcern(0)]
        );
        return $urls;
    }

    /**
     * Checks that host's robots.txt allows the url to be crawled
     *
     * @param string $hostname name of host (FQDN)
     * @param string $robotsTxt content of robots.txt file
     * @param string $url URL to check
     * @return bool true if the URL may be crawled
     */
    private function isCrawlingAllowed(string $hostname, string $robotsTxt, string $url) : bool
    {
        try {
            if (empty($robotsTxt)) {
                return true;
            }
            if (!parse_url($hostname, PHP_URL_SCHEME)) {
                $scheme = parse_url($url, PHP_URL_SCHEME);
                if (empty($scheme)) {
                    $hostname = "http:$hostname";
                    $url = "http:$url";
                }
            }
            // $client = ;
            return (!((new TxtClient($hostname, 200, $robotsTxt))->userAgent(self::USER_AGENT_NAME)->isDisallowed($url)));
        } catch (\Exception $e) {
            var_dump($e);
            return true;
        }
    }

    /**
     * Sets the status of a URL to STATUS_BLOCKED
     *
     * @param string $url URL to be updated
     * @return bool true if the status was updated
     */
    private function setUrlBlocked(string $url) : bool
    {
        $result = $this->collection->updateOne(
            ['url' => $url],
            ['$set' => ['status' => self::STATUS_BLOCKED]],
            ['writeConcern' => new WriteConcern(0)]
        );
        return true;
    }

    /**
     * Add a new page to the collection
     *
     * @param string $url URL of the page being added
     * @param string $pageContent content of the page (html)
     * @return bool true if page was added
     */
    public function addPage(string $url, string $pageContent) : bool
    {
        $result = $this->collection->updateOne(
                ['url' => $url],
                ['$set' => [
                    'status' => self::STATUS_FETCHED,
                    'content' => base64_encode(gzcompress(mb_convert_encoding($pageContent, 'UTF-8'))),
                    ]
                ]
            );
        return ($result->getModifiedCount() === 1);
    }

    /**
     * Returns the next page to be scraped
     *
     * returned object has properties: _id, url, content, status, host
     *
     * @return object the page
     */
    public function getNextPage() : ?object
    {
        $page = $this->collection->findOneAndUpdate(
                ['status' => self::STATUS_FETCHED],
                ['$set' => ['status' => self::STATUS_SCRAPING]]
            );
        if (!empty($page)) {
            $page->content = gzuncompress(base64_decode($page->content));
            $this->knownUrls[$page->url] = true;
        }
        return $page;
    }

    /**
     * Notifies the service that scraping has been completed for the given URL
     *
     * @param string $url URL of the page
     * @return bool true if scraping status was updated
     */
    public function finishedScraping(string $url) : bool
    {
        $this->collection->updateOne(
                ['url' => "$url"],
                [
                    '$set' => ['status' => self::STATUS_SCRAPED],
                    '$unset' => ['content' => '']
                ],
                ['writeConcern' => new WriteConcern(0)]
            );
        return true;
    }

    /**
     * Adds new URLs to the collection
     *
     * @param string[] $urls array of URLs
     * @return int number of new URLs added
     */
    public function addUrls(array $urls) : int
    {
        if (empty($urls)) {
            return 0;
        }
        $updates = [];
        foreach ($urls as $url) {
            if (!isset($this->knownUrls[$url])) {
                $host = (string) (new UrlHelper($url))->get('base');
                $this->hostsService->addHost($host);
                $updates[] = ['updateOne' => [
                            ['url' => "$url"],
                            ['$set' => ['url' => "$url", 'host' => $host]],
                            ['upsert' => true]
                        ]];
                $this->knownUrls[$url] = true;
            }
        }
        $this->pendingBulkWrites += $updates;
        if (count($this->pendingBulkWrites) >= $this->maxPendingBulkWrites) {
            $this->collection->bulkWrite(
                $this->pendingBulkWrites,
                ['ordered' => false, 'writeConcern' => new WriteConcern(0)]
            );
            $this->pendingBulkWrites = [];
        }
        if (count($this->knownUrls) > $this->maxKnownUrls) {
            $this->knownUrls = array_slice($this->knownUrls, (int) $this->maxKnownUrls / 2, null, true);
        }
        return count($updates);
    }

    /**
     * Reset pages from 'fetching' back to new urls
     *
     * @return int number of pages affected
     */
    public function resetFetching() : int
    {
        return $this->collection->updateMany(
                ['status' => self::STATUS_FETCHING],
                ['$unset' => ['status' => '']]
        )->getModifiedCount();
    }

    /**
    * Reset pages from 'scraping' back to 'fetched'
    *
    * @return int number of pages affected
    */
    public function resetScraping() : int
    {
        return $this->collection->updateMany(
                ['status' => self::STATUS_SCRAPING],
                ['$set' => ['status' => self::STATUS_FETCHED]]
        )->getModifiedCount();
    }

    public function getStatusCounts() : \Traversable
    {
        return $this->collection->aggregate([
                ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
                ['$sort' => ['_id' => 1]]
            ]);
    }

    /**
     * Returns the MongoDB collection of pages
     *
     * @return Collection
     */
    public function getCollection() : Collection
    {
        return $this->collection;
    }

    /**
     * undocumented function summary
     *
     * Undocumented function long description
     *
     * @param type var Description
     * @return return type
     */
    public function __destruct()
    {
        // do any pending bulk writes
        if (!empty($this->pendingBulkWrites)) {
            $this->collection->bulkWrite(
                $this->pendingBulkWrites,
                ['ordered' => false, 'writeConcern' => new WriteConcern(0)]
            );
        }
        // return unfetched urls with status: 'fetching' to status: [not set]
        if (!empty($this->urlsBatches)) {
            $updates = [];
            foreach ($this->urlsBatches as $hostname => $urls) {
                if (!empty($urls)) {
                    foreach ($urls as $url) {
                        $updates[] = ['updateOne' => [
                            ['url' => $url],
                            ['$unset' => ['status' => '']]
                        ]];
                    }
                }
            }
            $this->collection->bulkWrite($updates, ['ordered' => false, 'writeConcern' => new WriteConcern(0)]);
        }
    }
}
