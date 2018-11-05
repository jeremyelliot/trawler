<?php

namespace Trawler\Service\Interfaces;

interface HostsServiceInterface
{

    /**
     * Returns the next host to be crawled.
     *
     * The returned host will be the least-recently crawled host
     * with status OK.
     * Returned object includes robots.txt string
     *
     * @return object
     */
    public function getNextHostToCrawl() : ?object;

    /**
     * Adds a new host to the collection.
     *
     * @param string $hostname hostname (base URL)
     * @return bool true if new host was inserted
     */
    public function addHost(string $hostname) : bool;


    /**
     * Returns the next host to have it's metadata updated
     *
     * @return object host
     */
    public function getNextHostToUpdate() : ?object;

    /**
     * Updates the collection with new host data
     *
     * @param object $host host object with fresh metadata
     * @return bool true if the host document was updated
     */
    public function updateHost(object $host) : bool;
}
