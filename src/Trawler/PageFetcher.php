<?php

namespace Trawler;

use \Trawler\Service\HostsService;
use \Trawler\Service\PagesService;
use Trawler\Service\Interfaces\PagesServiceInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches pages into a MongoDB collection
 */
class PageFetcher
{
    const USER_AGENT_NAME = 'Trawler/1.0';

    const MAX_WAIT = 3000000;
    const MIN_WAIT = 10000;
    const MAX_PAGE_SIZE = 2000000;

    /** @var PagesServiceInterface */
    private $pagesService;
    /** @var callable */
    private $exceptionHandler;
    /** @var callable */
    private $messageListener;
    /** @var callable */
    private $contentFilter;
    /** @var LoggerInterface */
    private $verboseLogger;
    private $verbose = false;

    private $options = [
        'request' => [
            'timeout' => 10,
            'headers' => ['User-Agent' => self::USER_AGENT_NAME]
        ],
        'response' => [
            'acceptContentTypes' => [
                'text/html',
                'application/xhtml+xml',
                'application/xml'
            ],
            'acceptLanguages' => ['en']
        ]
    ];

    /**
     *
     *
     * @param PagesServiceInterface $pagesService
     * @param callable $exceptionHandler
     * @param array $options
     */
    public function __construct(PagesServiceInterface $pagesService, callable $exceptionHandler=null, array $options=null)
    {
        $this->pagesService = $pagesService;
        $this->exceptionHandler = $exceptionHandler ?: function (\Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
        };
        if ($options !== null) {
            $this->options = $options;
        }
        if (empty($this->options['request']['headers']['Accept'])
                && !empty($this->options['response']['acceptContentTypes'])) {
            $this->options['request']['headers']['Accept']
                = implode(', ', $this->options['response']['acceptContentTypes']);
        }
    }

    public function setVerboseLogger(LoggerInterface $logger) : PageFetcher
    {
        $this->verboseLogger = $logger;
        $this->verbose = true;
        return $this;
    }

    /**
     * Set a filter function to process the fetched page content before it is stored.
     *
     * function will be given a string of page content and should return the processed content.
     * eg. function ($content) { return strtolower($content); }
     *
     * @param callable $filter
     * @return PageFetcher
     */
    public function setContentFilter(callable $filter) : PageFetcher
    {
        $this->contentFilter = $filter;
        return $this;
    }

    /**
     * Returns the content filter function
     *
     * @return callable filter function
     */
    public function getContentFilter() : callable
    {
        return $this->contentFilter;
    }

    /**
     * The generator loop - start it up and let it run
     */
    public function loop(bool &$stopSignal) : \Generator
    {
        $this->report('starting generator ');
        $wait = self::MIN_WAIT;
        while (!$stopSignal) {
            $url = $this->pagesService->getNextUrl();
            if (empty($url)) {
                $wait = min($wait, self::MAX_WAIT);
                $this->report("waiting $wait microseconds");
                usleep($wait *= 2);
            } else {
                $content = $this->fetchContent($url);
                if (isset($this->contentFilter)) {
                    $content = $this->getContentFilter()($content);
                }
                $this->pagesService->addPage($url, $content);
                yield $url;
                $wait = self::MIN_WAIT;
            }
        }
    }

    /**
     * Fetches content of page at the given url
     *
     * @param string url of page to fetch
     * @return string content of the page
     */
    private function fetchContent(string $url) : string
    {
        try {
            $content = '';
            $this->report("requesting $url");
            $response = (new GuzzleClient())->get($url, $this->options['request']);
            $this->report("got response " . $response->getStatusCode());
            if ($this->isAcceptedContentType($response) && $this->isAcceptedLanguage($response)) {
                $this->report('Receiving body...');
                $content = mb_convert_encoding($response->getBody(), 'UTF-8');
                $this->report(mb_strlen($content) . " chars received.\n");
            }
            return $content;
        } catch (BadResponseException $e) {
            $this->report('failed with error ' . $e->getResponse()->getStatusCode(), $e);
        } catch (\Exception $e) {
            $handler = $this->exceptionHandler;
            if (\is_callable($handler)) {
                $handler($e);
            } else {
                throw $e;
            }
        }
        return '';
    }

    /**
     * Checks that content-type header is accepted by filter options
     *
     * @param ResponseInterface $response response to be checked
     * @return bool true if content type is accepted
     */
    private function isAcceptedContentType(ResponseInterface $response) : bool
    {
        // extract MIME-Type from Content-Type header
        $contentType = ($response->hasHeader('Content-Type'))
                ? explode(';', $response->getHeader('Content-Type')[0])[0]
                : false;
        $accepted = (!$contentType || in_array($contentType, $this->options['response']['acceptContentTypes']));
        $this->report("Content-Type: $contentType " . ($accepted ? 'accepted' : 'rejected.'));
        return $accepted;
    }

    /**
     * Checks that language header is accepted by filter options.
     *
     * Empty language header will be accepted
     *
     * @param ResponseInterface $response response to be checked
     * @return bool true if language header is accepted
     */
    private function isAcceptedLanguage(ResponseInterface $response) : bool
    {
        if (!$response->hasHeader('Content-Language')) {
            $this->report('no language header: accepted');
            return true; // no Content-Language header, so optimistically accept
        }
        $header = $response->getHeader('Content-Language')[0];
        foreach (explode(',', \str_replace(' ', '', $header)) as $languageTag) {
            if (in_array($languageTag, $this->options['response']['acceptLanguages'])) {
                $this->report("[Content-Language: $header] $languageTag accepted");
                return true;
            }
            $primary = explode('-', $languageTag)[0];
            if (in_array($primary, $this->options['response']['acceptLanguages'])) {
                $this->report("[Content-Language: $header] $primary accepted");
                return true;
            }
        }
        $this->report("$header rejected");
        return false;
    }

    /**
     * report info to logger if verbose is true
     *
     * @param string $message
     */
    private function report(string $message)
    {
        if ($this->verbose) {
            $this->verboseLogger->info($message);
        }
    }
}
