<?php

namespace Trawler\Service\Interfaces;

interface UrlsServiceInterface
{
    /**
     * Get the next URL of a next page to be fetched
     *
     * @return string URL
     */
    public function getNextUrl() : string;

    /**
     * Adds new URLs to the collection
     *
     * @param array<string> $urls array of URLs
     * @return int number of new URLs added
     */
    public function addUrls(array $urls) : int;
}
