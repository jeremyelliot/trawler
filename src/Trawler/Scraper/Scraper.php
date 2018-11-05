<?php

namespace Trawler\Scraper;

/**
 *
 */
interface Scraper
{
    /**
     * Takes a URL and its HTML page.
     * Implementing classes can do whatever extraction and are responsible for doing
     *
     * @param string $pageUrl The source URL for the page
     * @param string $html HTML source of the page to be processed
     * @return string information about the extraction
     */
    public function extractFrom(string $pageUrl, string $html) : string;
}
