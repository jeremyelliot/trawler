<?php

namespace Trawler\Scraper;

use \Trawler\Service\PagesService;
use \Trawler\UrlExtractor;

/**
 * Extracts URLs from HTML text.
 *
 * URLs are passed to the PagesService for storage.
 *
 */
class UrlScraper implements Scraper
{


    /** @var PagesService */
    private $pagesService;

    /**
     * @var array
     */
    private $options = [];

    /**
     *
     * @var UrlExtractor
     */
    private $urlExtractor;

    /**
     * $options array includes filters for URL file extensions, domains, and schemes
     * @see UrlExtractor::__construct
     *
     * @param PagesService $pagesService
     * @param array $options Options
     */
    public function __construct(PagesService $pagesService, array $options)
    {
        $this->pagesService = $pagesService;
        $this->options = $options;
        $this->urlExtractor = new UrlExtractor($options);
    }

    /**
     * Takes URL and content of a page to be scraped
     *
     * @param string $pageUrl url of the page
     * @param string $html content of the page
     * @return string message about the extraction result
     */
    public function extractFrom(string $pageUrl, string $html) : string
    {
        $insertedCount = 0;
        $urls = $this->urlExtractor->getAbsoluteUrls($pageUrl, $html);
        // $urls = (new UrlExtractor($this->options))->getAbsoluteUrls($pageUrl, $html);
        $urlCount = count($urls);
        if ($urlCount > 0) {
            $insertedCount = $this->pagesService->addUrls($urls);
        }
        $shortUrl = (strlen($pageUrl) > 80)
            ? substr($pageUrl, 0, 60) . '.....' . substr($pageUrl, -15)
            : $pageUrl;
        return "$shortUrl -->  urls: $urlCount, new: $insertedCount";
    }
}
