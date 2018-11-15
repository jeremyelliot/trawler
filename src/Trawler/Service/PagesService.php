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
use Ds\Deque;
use Ds\Sequence;
use Ds\Vector;
use Ds\Set;
use Ds\Map;

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
     * urls known to exist already, to reduce unnecessary updates (Set)
     * @var Set $knownUrls
     */
    private $knownUrls;

    /**
     * Maximum size of $knownUrls array (int)
     * @var int
     */
    private $maxKnownUrls = 10000;

    /**
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
     * Map<array> $urlsBatches
     */
    private $urlsBatches;

    /**
     * Size of batches of hosts and urls
     * If autoIncreaseBatchSize is true, batchSize is the initial size.
     * @var int
     */
    private $batchSize = 20;

    /**
    * Increases batchSize +1 each time the hosts cycle finishes before (crawlDelay) milliseconds.
    * batchSize can be auto-increased until it reaches $maxBatchSize.
    * @var int $hostsCrawlDelay
    */
    private $autoIncreaseBatchSize = false;

    /**
     * Maximum size of batches when autoIncreaseBatchSize is true
     * @var int
     */
    private $maxBatchSize = 32;

    /**
     * Ds\Deque $hostsCache
     * @var Deque
     */
    private $hostsCache;
    private $hostsCacheIterations = 1;
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

    /**
     * @var Vector
     */
    private $urlsPendingUpdate;

    /**
     * Bulk update will be done once number of pending urls exceeds maxUrlsPendingUpdate
     * @var int
     */
    private $maxUrlsPendingUpdate = 200;

    private $wasUrlFound = false;

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
        foreach (['batchSize', 'maxBatchSize', 'autoIncreaseBatchSize', 'maxUrlsPendingUpdate', 'maxKnownUrls',
                    'preloadKnownUrls', 'crawlDelay'] as $key) {
            if (isset($serviceOptions[$key])) {
                $this->$key = $serviceOptions[$key];
            }
        }
        if ($this->preloadKnownUrls) {
            $this->loadKnownUrls();
        }
        $this->knownUrls = new Set();
        $this->hostsCycleTime = (int) ((microtime(true) * 1000) - $this->crawlDelay);
        $this->hostsCache = new Deque();
        $this->hostsCache->allocate($this->batchSize);
        $this->urlsBatches = new Map();
        $this->urlsPendingUpdate = new Vector();
    }

    /**
     * Preloads the knownUrls cache array
     */
    private function loadKnownUrls()
    {
        $this->knownUrls = new Set(
            array_map(
                function ($page) {
                    return $page->url;
                },
                $this->collection->find([], ['limit' => (int) ($this->maxKnownUrls / 2)])->toArray()
            )
        );
    }

    /**
     * Get the next URL of the next page to be fetched
     *
     * @return string URL
     */
    public function getNextUrl() : string
    {
        do {
            $host = $this->getNextHost();
            // if there is no next host, nothing more can be done
            if (empty($host)) {
                return '';
            }
            do {
                // get the next url for selected host
                $url = $this->getNextUrlForHost($host->host);
                // if there is no next url for this host,
                if (empty($url)) {
                    // Remmove host from the hostsCache
                    $hostIndex = $this->hostsCache->find($host);
                    if ($hostIndex !== false) {
                        $this->hostsCache->remove($hostIndex);
                    }
                    $host->status = HostsService::HOST_STATUS_DONE;
                    $this->hostsService->updateHost($host);
                    // return to outer loop to get next host
                    break;
                }
                $isCrawlingAllowed = $this->isCrawlingAllowed($host->host, $host->robots->txt, $url);
                if (!$isCrawlingAllowed) {
                    $this->setUrlBlocked("$url");
                    $url = '';
                }
            } while (!$isCrawlingAllowed);
        } while (empty($url));
        $this->wasUrlFound = true; // URL has been handed out so getNextHost might have to sleep to respect crawlDelay
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
        // replace a host in the cache ofter (batchSize) iterations
        if ($this->hostsCacheIterations > ($this->batchSize * $this->batchSize)) {
            if (!$this->hostsCache->isEmpty()) {
                $removedHost = $this->hostsCache->pop();
                $this->removeHostUrls($removedHost->host);
            }
            $this->hostsCacheIterations = 1;
        }
        // if beginning hosts cycle again ...
        if ($this->hostsCacheIterations % $this->batchSize === 0) {
            // if no url was found during previous cycle, no wait is required
            if ($this->wasUrlFound) {
                $nowMillis = (int) (microtime(true) * 1000);
                $millisToWait = $this->crawlDelay + $this->hostsCycleTime - $nowMillis;
                if ($millisToWait > 0) {
                    if ($this->autoIncreaseBatchSize) {
                        $this->batchSize = min(++$this->batchSize, $this->maxBatchSize);
                    }
                    usleep($millisToWait * 1000);
                }
                $this->wasUrlFound = false; // don't sleep if no URLs where handed out in the previous iteration
                $this->hostsCycleTime = $nowMillis;
            }
        }
        // if hostsCache is not full, get a new host from the hostsService
        if ($this->hostsCache->count() < $this->batchSize) {
            $host = $this->hostsService->getNextHostToCrawl();
        } else {
            // get the next host from the cache
            $host = $this->hostsCache->shift();
        }
        // move host to back of queue
        $this->hostsCache->push($host);
        $this->hostsCacheIterations++;
        return $host;
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
    private function getNextUrlForHost(string $hostname) : ?string
    {
        if (!$this->urlsBatches->hasKey($hostname) || $this->urlsBatches->get($hostname)->isEmpty()) {
            // get another batch of urls for $hostname
            $this->urlsBatches->put(
                $hostname,
                $this->getNextUrlsBatch($hostname)->map(function ($url) {
                    return $url->url;
                })
            );
        }
        // still no urls? remove host from urlsBatches
        if ($this->urlsBatches->get($hostname)->isEmpty()) {
            $this->urlsBatches->remove($hostname);
            return null;
        }
        return $this->urlsBatches->get($hostname)->pop();
    }

    /**
     * fetches and returns a batch of urls
     *
     * @param string $hostname name of the host
     * @return Sequence url objects
     */
    private function getNextUrlsBatch(string $hostname) : Vector
    {
        $urls = $this->collection->find(
            [
                'status' => ['$exists' => false],
                'host' => $hostname
            ],
            ['limit' => $this->batchSize]
        )->toArray();
        if (!empty($urls)) {
            $this->collection->bulkWrite(
                array_map(function ($url) {
                    return ['updateOne' => [
                                ['_id' => $url->_id],
                                ['$set' => ['status' => self::STATUS_FETCHING]]
                            ]];
                }, $urls),
                ['ordered' => false, 'writeConcern' => new WriteConcern(0)]
            );
        }
        return new Vector($urls);
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
            $this->knownUrls->add($page->url);
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
     * @param array $urls array of URLs
     * @return int number of new URLs added
     */
    public function addUrls(array $urls) : int
    {
        if (empty($urls)) {
            return 0;
        }
        $pendingBefore = $this->urlsPendingUpdate->count();
        // filter out known urls and iterate over the new urls
        foreach (array_filter($urls, function ($url) {
            return !$this->knownUrls->contains($url);
        }) as $url) {
            // add the host from this url
            $this->hostsService->addHost((string) (new UrlHelper($url))->get('base'));
            $this->urlsPendingUpdate->push($url);
            $this->knownUrls->add($url);
        }
        $numNewUrls = $this->urlsPendingUpdate->count() - $pendingBefore;
        // if there are enough pending urls, do the bulk update
        if ($this->urlsPendingUpdate->count() >= $this->maxUrlsPendingUpdate) {
            $this->updateUrls($this->urlsPendingUpdate);
            $this->urlsPendingUpdate->clear();
        }
        // if knownUrls list is too big, discard the older half
        if ($this->knownUrls->count() > $this->maxKnownUrls) {
            $this->knownUrls = $this->knownUrls->slice((int) $this->maxKnownUrls / -2);
        }
        return $numNewUrls;
    }

    /**
     * Do the bulk update of urls
     *
     * @param \Ds\Sequence $urls
     */
    private function updateUrls(Sequence $urls)
    {
        $this->collection->bulkWrite(
            $urls->map(function ($url) {
                return  ['updateOne' => [
                            ['url' => "$url"],
                            ['$set' => ['url' => "$url", 'host' => (string) (new UrlHelper($url))->get('base')]],
                            ['upsert' => true]
                        ]];
            })->toArray(),
            ['ordered' => false, 'writeConcern' => new WriteConcern(0)]
        );
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
     * Commit pending url updates and reset status of pages in processing states
     */
    public function __destruct()
    {
        // do any pending bulk writes
        if (!$this->urlsPendingUpdate->isEmpty()) {
            $this->updateUrls($this->urlsPendingUpdate);
        }
        // return unfetched urls with status: 'fetching' to status: [not set]
        if (!$this->urlsBatches->isEmpty()) {
            $updates = [];
            foreach ($this->urlsBatches as $hostname => $urls) {
                if (!$urls->isEmpty()) {
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

    /**
     * Remove a host and its urls from urlsBatches
     *
     * Removed urls have their status unset from 'fetching' to [not exists]
     *
     * @param string $hostname
     */
    private function removeHostUrls(string $hostname)
    {
        if (!$this->urlsBatches->hasKey($hostname)) {
            return;
        }
        if (!$this->urlsBatches->get($hostname)->isEmpty()) {
            foreach ($this->urlsBatches->get($hostname) as $url) {
                $updates[] = ['updateOne' => [
                    ['url' => $url],
                    ['$unset' => ['status' => '']]
                ]];
            }
            $this->collection->bulkWrite($updates, ['ordered' => false, 'writeConcern' => new WriteConcern(0)]);
        }
        $this->urlsBatches->remove($hostname);
    }
}
