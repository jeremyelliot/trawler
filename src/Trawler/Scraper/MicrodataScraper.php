<?php

namespace Trawler\Scraper;

use \Trawler\Repository\MicrodataRepository;
use Jkphl\Micrometa\Ports\Parser;
use Jkphl\Micrometa\Ports\Format;

class MicrodataScraper implements Scraper
{
    /** @var MicrodataRepository */
    private $repository;

    private $parseFormats = Format::ALL - Format::JSON_LD - Format::LINK_TYPE;

    private $options = [];

    /**
     * Microdata/Microformats parser
     *
     * @var Parser;
     */
    protected $parser;

    /**
     * @param MicrodataRepository
     */
    public function __construct(MicrodataRepository $repository)
    {
        $this->repository = $repository;
        $this->parser = new Parser($this->parseFormats);
    }

    public function extractFrom(string $pageUrl, string $html) : string
    {
        $items = [];
        $parser = $this->parser;
        $items = $parser($pageUrl, $html)->toObject()->items;
        if (!empty($items)) {
            $this->repository->insertMicrodata($pageUrl, $items);
        }
        $numItems = count($items);
        return ($numItems > 0) ? "microdata items: $numItems" : '';
    }
}
