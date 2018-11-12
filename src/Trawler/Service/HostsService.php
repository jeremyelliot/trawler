<?php

namespace Trawler\Service;

use \MongoDB\Client;
use \MongoDB\Collection;
use \MongoDB\Driver\WriteConcern;
use MongoDB\Driver\Cursor;
use MongoDB\BSON\UTCDateTime;
use \Trawler\Service\Interfaces\HostsServiceInterface;
use Ds\Set;
use Ds\Vector;

/**
 * Provides hosts data
 */
class HostsService implements HostsServiceInterface
{
    const HOST_STATUS_OK = 'ok';
    const HOST_STATUS_BLOCKED = 'blocked';
    const HOST_STATUS_EXCLUDE = 'exclude';
    const HOST_STATUS_ERROR = 'error';
    const HOST_STATUS_DONE = 'done';

    /**
     * @var Collection
     */
    private $collection;

    // TODO: make this configurable
    /**
     * milliseconds between fetches from a given host
     * @var int
     */
    private $crawlDelay = 20 * 1000;

    /**
     * milliseconds between refreshing host data
     * @var int
     */
    private $hostRefreshPeriod = 7 * 24 * 60 * 60 * 1000;

    /**
     * Array of know hostnames, to reduce number of db requests
     * @var Set
     */
    private $knownHosts;// = [];

    /**
     * Maximum size of $knownHosts array
     * @var int
     */
    private $maxKnownHosts = 4000;

    private $batchSize = 100;
    /**
     *
     * @var Vector
     */
    private $hostsBatch = [];

    /**
     * Takes an array of options for connecting to the database
     *
     * $databseOptions example: [
     *      'host' => '127.0.0.1',
     *      'port' => 27017,
     *      'database' => 'trawler',
     *      'username' => 'trawler',
     *      'password' => 'somepassword',
     *      'collection' => 'trawlerHosts']
     * $serviceOptions exeample: [
     *      'crawlDelay' => 20 * 1000, // (milliseconds)
     *      'hostRefreshPeriod' => 24 * 60 * 60 * 1000, // (milliseconds) period between refreshes for a host
     *      'maxKnownHosts' => 4000 // (items) maximum size of in-memory hostnames cache
     * ]
     *
     * @param array $databseOptions options for connecting to the database
     * @param array $serviceOptions options for this service
     */
    public function __construct(array $databaseOptions, array $serviceOptions=[])
    {
        extract($databaseOptions);
        $client = new Client("mongodb://$username@$host:$port/$database", [
            'password' => urlencode($password)
        ]);
        $this->collection = $client->$database->$collection;
        foreach (['crawlDelay', 'hostRefreshPeriod', 'maxKnownHosts', 'batchSize'] as $key) {
            if (isset($serviceOptions[$key])) {
                $this->$key = $serviceOptions[$key];
            }
        }
        $this->knownHosts = new Set();
        $this->hostsBatch = new Vector();
    }

    /**
     * Returns the next host to be crawled.
     *
     * The returned host will be the least-recently crawled host
     * with status OK.
     * Returned object includes robots.txt string
     *
     * @return object
     */
    public function getNextHostToCrawl() : ?object
    {
        if ($this->hostsBatch->isEmpty()) {
            $this->hostsBatch->push(...$this->getNextHostsBatch());
        }
        return ($this->hostsBatch->isEmpty()) ? null : $this->hostsBatch->pop();
    }

    /**
     * Gets a batch of host objects from the collection
     *
     * @return array batch of hosts
     */
    private function getNextHostsBatch() : ?array
    {
        // find the next host that has waited long enough
        $hosts = $this->collection->find(
            [
                '$or' => [
                    ['fetched' => ['$exists' => false]],
                    ['fetched' => ['$lt' => new UTCDateTime(floor(microtime(true) * 1000) - $this->crawlDelay)]]
                ],
                'status' => self::HOST_STATUS_OK
            ],
            [
                'limit' => $this->batchSize,
                'sort' => ['fetched' => 1]
            ]
        )->toArray();
        if (!empty($hosts)) {
            $this->collection->bulkWrite(
                array_map(function ($host) {
                    $this->knownHosts->add($host->host);
                    return ['updateOne' => [
                                ['_id' => $host->_id],
                                ['$currentDate' => ['fetched' => true]]
                            ]];
                }, $hosts),
                ['ordered' => false, 'writeConcern' => new WriteConcern(0)]
            );
        }
        return $hosts;
    }

    /**
     * Adds a new host to the collection.
     *
     * @param string $hostname hostname (FQDN)
     * @return bool true if new host was inserted
     */
    public function addHost(string $hostname) : bool
    {
        if ($this->knownHosts->contains($hostname)) {
            return false;
        }
        $result = $this->collection->updateOne(
            ['host' => $hostname],
            ['$set' => ['host' => $hostname]],
            ['upsert' => true]
        );
        $this->knownHosts->add($hostname);
        // if knownHosts array gets too big, remove half of it
        if ($this->knownHosts->count() > $this->maxKnownHosts) {
            $this->knownHosts = $this->knownHosts->slice((int) ($this->maxKnownHosts / -2));
        }
        return ($result->getUpsertedCount() === 1);
    }

    /**
     * Returns the next host to have it's metadata updated
     *
     * @return object host
     */
    public function getNextHostToUpdate() : ?object
    {
        $result = $this->collection->find(
            ['$or' => [
                ['status' => ['$exists' => false]],
                ['updated' => ['$lt' => new UTCDateTime(floor(microtime(true) * 1000) - $this->hostRefreshPeriod)]]
            ]],
            [
                'limit' => 1,
                'sort' => ['updated' => 1]
            ]
        )->toArray();
        return (empty($result)) ? null : $result[0];
    }

    /**
     * Updates the collection with new host data
     *
     * @param object $host host object with fresh metadata
     * @return bool true if the host document was updated
     */
    public function updateHost(object $host) : bool
    {
        $result = $this->collection->updateOne(
            ['_id' => $host->_id],
            [
                '$set' => [
                    'robots.txt' => $host->robots->txt,
                    'status' => $host->status
                ],
                '$currentDate' => ['updated' => true]
            ]
        );
        return ($result->getModifiedCount() === 1);
    }
}
