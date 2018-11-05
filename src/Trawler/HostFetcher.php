<?php

namespace Trawler;

use \Trawler\Service\HostsService;
use \Trawler\Service\Interfaces\HostsServiceInterface;
use \GuzzleHttp\Client as GuzzleClient;
use \GuzzleHttp\Exception\BadResponseException;
use \GuzzleHttp\Psr7\Response;

/**
 * Fetches host data (eg. robots.txt) into a MongoDB collection
 */
class HostFetcher
{
    /**
     * Maximum wait time before trying for next host (microseconds)
     */
    const MAX_WAIT = 30000000; // 30 seconds
    /**
     * Minimum wait time before trying for next host (microseconds)
     * Wait time doubles each time the repo is polled for the next host,
     * until a host is available, then it is reset to MIN_WAIT
     */
    const MIN_WAIT = 1 * 1000 * 1000;

    /** @var HostsServiceInterface */
    private $hostsService;
    /** @var callable */
    private $exceptionHandler;
    /** @var callable */
    private $messageListener;

    /** @var int */
    private $options;

    public function __construct(HostsServiceInterface $hostsService, callable $exceptionHandler)
    {
        $this->hostsService = $hostsService;
        $this->exceptionHandler = $exceptionHandler;
        $this->options = ['request' => ['timeout' => 8]];
    }

    public function setMessageListener(callable $listener) : HostFetcher
    {
        $this->messageListener = $listener;
        return $this;
    }

    public function getMessageListener() : callable
    {
        return $this->messageListener;
    }

    public function loop(bool &$stopSignal) : \Generator
    {
        $wait = self::MIN_WAIT;
        while (!$stopSignal) {
            $host = $this->hostsService->getNextHostToUpdate();
            $wait = min($wait, self::MAX_WAIT);
            usleep($wait *= 2);
            if (!empty($host)) {
                // fetch robots.txt content
                if (!isset($host->robots)) {
                    $host->robots = (object) ['txt' => null];
                }
                $host->robots->txt = mb_convert_encoding($this->fetchContent($host->host), 'UTF-8');
                // update host data
                $host->status = (empty($host->status)) ? HostsService::HOST_STATUS_OK : $host->status;
                $this->hostsService->updateHost($host);
                yield $host->host;
                $wait = self::MIN_WAIT;
            }
        }
    }

    private function fetchContent(string $hostname) : string
    {
        try {
            $content = '';
            $this->report("$hostname: requesting...");
            $response = (new GuzzleClient())->get("$hostname/robots.txt", $this->options['request']);
            $this->report("got response " . $response->getStatusCode());
            $this->report(' -- Receiving body...');
            $content = $response->getBody();
            $this->report(strlen($content) . " chars received.\n");
            return $content;
        } catch (BadResponseException $e) {
            $this->report('failed with error ' . $e->getResponse()->getStatusCode() . PHP_EOL);
        } catch (\Exception $e) {
            $handler = $this->exceptionHandler;
            $handler($e);
        }
        return '';
    }

    private function report($text)
    {
        if (!empty($this->messageListener)) {
            $this->getMessageListener()($text);
        }
        return;
    }
}
