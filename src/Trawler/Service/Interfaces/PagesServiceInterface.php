<?php

namespace Trawler\Service\Interfaces;

interface PagesServiceInterface
{
    /**
     * Get the next URL of a next page to be fetched
     *
     * @return string URL
     */
    public function getNextUrl() : string;

    /**
     * Add a new page to the collection
     *
     * @param string $url URL of the page being added
     * @param string $pageContent content of the page (html)
     * @return bool true if page was added
     */
    public function addPage(string $url, string $pageContent) : bool;

    /**
     * Returns the next page to be scraped
     *
     * returned object has properties: _id, url, content, status, host
     *
     * @return object the page
     */
    public function getNextPage() : ?object;

    /**
     * Notifies the service that scraping has been completed for the given URL
     *
     * @param string $url URL of the page
     * @return bool true if scraping status was updated
     */
    public function finishedScraping(string $url) : bool;

    /**
     * Adds new URLs to the collection
     *
     * @param array<string> $urls array of URLs
     * @return int number of new URLs added
     */
    public function addUrls(array $urls) : int;
}
