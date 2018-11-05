<?php

namespace Trawler;

use \Trawler\Service\PagesService;
use \Trawler\Scraper\Scraper;
use \Trawler\Service\Interfaces\PagesServiceInterface;

/**
 *
 */
class ScraperRunner
{
    /** Maximum wait time before trying for next page (microseconds) */
    const MAX_WAIT = 5000000;
    /**
     * Minimum wait time before trying for next page (microseconds)
     * Wait time doubles each time the repo is polled for the next page,
     * until a page is available, then it is reset to MIN_WAIT
     */
    const MIN_WAIT = 100000;

    /**
     * @var PagesServiceInterface
     */
    private $pagesService;

    /**
     * Function to handle exceptions that might fall out of the Scrapers.
     *
     * The function must take an Exception as the only argument.
     * Gives opportunity to keep the scraping loop going continuosly
     * by ignoring some errors
     *
     * @var callable
     */
    private $exceptionHandler;

    /**
     * array of Scrapers that will be called on each page
     *
     * @var array<Scraper>
     */
    private $scrapers;

    public function __construct(PagesServiceInterface $pagesService, callable $exceptionHandler=null)
    {
        $this->pagesService = $pagesService;
        $this->exceptionHandler = $exceptionHandler ?: function (\Exception $e) {
            fwrite(STDERR, $e->getMessage());
        };
    }

    public function loop(bool &$stopSignal) : \Generator
    {
        $wait = self::MIN_WAIT;
        while (!$stopSignal) {
            $page = $this->pagesService->getNextPage();
            if (empty($page)) {
                $wait = min($wait, self::MAX_WAIT);
                usleep($wait *= 2);
            } else {
                if (!empty($page->content)) {
                    foreach ($this->scrapers as $scraper) {
                        yield $this->runScraper($scraper, $page->url, $page->content);
                    }
                }
                $this->pagesService->finishedScraping($page->url);
                $wait = self::MIN_WAIT;
            }
        }
    }

    private function runScraper(Scraper $scraper, string $url, string $html)
    {
        try {
            $result = "Failed scraping $url";
            $result = $scraper->extractFrom($url, $html);
            return $result;
        } catch (\Exception $e) {
            $exceptionHandler = $this->exceptionHandler;
            $exceptionHandler($e);
        }
        return $result;
    }

    public function addScraper(Scraper $scraper)
    {
        $this->scrapers[] = $scraper;
        return $this;
    }
}
