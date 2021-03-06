<?php

namespace Trawler;

use \JeremyElliot\UrlHelper;
use \Ds\Map;
use \Ds\Sequence;
use \Ds\Vector;

/**
 * Extracts URLs from an HTML document string
 *
 * The URLs can
 */
class UrlExtractor
{
    /**
     * xpath query to find href attributes
     * @var string
     */
    private $hrefQuery = '//*[@href != ""'
        . ' and not(starts-with(@href, "javascript:"))'
        . ' and not(starts-with(@href, "mailto:"))'
        . ' and not(starts-with(@href, "tel:"))'
        . ']/@href';

    /**
     * URL filtering options
     * @var stdClass
     */
    private $options;

    /** @var Map */
    private $domainAcceptedCache;
    // TODO: make configurable
    private $domainAcceptedCacheSize = 10000;
    private $domainAcceptedCacheChunkSize = 100;


    /**
     * @var string the URL context from which to create absolute urls
     */
    private $context;

    /**
     * $options array includes filter options for URL file extensions, domains, and schemes
     *
     * - 'extensions' and 'schemes' are accepted if they are in 'accept' OR not in 'reject'
     * - 'domains' are accepted if they match patterns in 'accept' AND do not match patterns in 'reject'
     *
     * $exampleOptions = [
     *     'extensions' => [
     *         'accept' => ['html', 'php', 'jsp', 'aspx', 'cf'],
     *         'reject' => ['js', 'css', 'jpg', 'png', 'jpeg', 'gif', 'ico']
     *     ],
     *     'domains' => [
     *         'accept' => ['.nz', '.org', '.kiwi'],
     *         'reject' => ['.wordpress.', '.govt.', '.google.', '.instagram.']
     *     ],
     *     'schemes' => [
     *         'accept' => ['http', 'https'],
     *         'reject' => ['apt']
     *     ],
     *     'distinctUrls' => true
     * ]
     *
     * @param array $options Options
     */
    public function __construct(array $options)
    {
        // for 'extensions' and 'schemes'
        // switch 'accept' and 'reject' entries from values to keys,
        // so we can use isset() which is O(1) instead of in_array() which is O(n)
        foreach (['extensions', 'schemes'] as $key) {
            if (isset($options[$key])) {
                foreach (['accept', 'reject'] as $type) {
                    if (isset($options[$key][$type])) {
                        $arr = $options[$key][$type];
                        $options[$key][$type] = array_flip($arr);
                    }
                }
            }
        }
        $this->options = (object) $options;
        $this->domainAcceptedCache = new Map();
    }

    /**
     * Returns absolute URLs based on the context URL
     *
     * @param string $contextUrl the prefix for relative urls
     * @param string $html the page to extract urls from
     * @param string $parts optional dot-separated string of urls segments to use @see UrlHelper::get($parts)
     * @return array extracted absolute URLs
     */
    public function getAbsoluteUrls(string $contextUrl, string $html, string $parts='base.dir.file.ext.query') : array
    {
        $this->context = $contextUrl;
        $urls = $this->getUrls($html, $parts);
        // $this->context may have been changed by the call to ->getUrls()
        $context = new UrlHelper($this->context);
        return array_map(function ($urlString) use ($context) {
            $url = new UrlHelper($urlString);
            if ($url->isAbsolute()) {
                return (string) $url;
            } elseif ($url->isRootRelative()) {
                return $context->get('base') . $url;
            } else {
                return $context->getContextPart() . $url;
            }
        }, $urls);
    }

    /**
    * Returns URLs extracted from the HTML text
    *
    * @param string $html the page to extract urls from
    * @param string $parts optional dot-separated string of urls segments to use @see UrlHelper::get($parts)
    * @return array extracted URLs
    */
    public function getUrls(string $html, string $parts='base.dir.file.ext.query') : array
    {
        if ($this->domainAcceptedCache->count() > $this->domainAcceptedCacheSize) {
            $this->domainAcceptedCache = $this->domainAcceptedCache
                ->slice(- $this->domainAcceptedCacheSize + $this->domainAcceptedCacheChunkSize);
        }
        return array_map(
            function ($url) {
                return (string) $url;
            },
            $this->filterExtensions(
                    $this->filterDomains(
                        $this->filterSchemes(
                            $this->getAllUrls($html, $parts)
                        )
                    )
                )->toArray()
        );
    }

    /**
    * Filter excludes URLs that have a file extensions (eg. .css) in the list of ignored extensions.
    *
    * Does not exclude URLs with no host.
    *
    * @param Sequence $urls URLs to filter
    * @return Sequence filtered URLs
    */
    private function filterExtensions(Sequence $urls) : Sequence
    {
        return (empty($this->options->extensions))
            ? $urls
            : $urls->filter(function ($url) {
                $ext = $url->getPart('ext');
                return (
                    empty($ext)
                    || isset($this->options->extensions['accept'][strtolower($ext)])
                    || !isset($this->options->extensions['reject'][strtolower($ext)])
                );
            });
    }

    /**
    * Filter excludes URLs that have a domain in the list of ignored domains.
    *
    * Does not exclude URLs with no host.
    *
    * @param Sequence $urls URLs to filter
    * @return Sequence filtered URLs
    */
    private function filterDomains(Sequence $urls) : Sequence
    {
        return (empty($this->options->domains))
            ? $urls
            : $urls->filter(function ($url) {
                $urlHost = (string) $url->getPart('host');
                if (empty($urlHost)) {
                    return true;
                }
                // if the host name has been checked before, use the stored result
                if ($this->domainAcceptedCache->hasKey($urlHost)) {
                    return $this->domainAcceptedCache->get($urlHost);
                }
                // not cached, do matching
                $accepted = false;
                // empty domains->accept means accept all
                if (empty($this->options->domains['accept'])) {
                    $accepted = true;
                } else { // otherwise check acceptance
                    foreach ($this->options->domains['accept'] as $acceptedDomain) {
                        if ($this->isDomainMatch($acceptedDomain, $urlHost)) {
                            $accepted = true;
                            break;
                        }
                    }
                }
                // there's still a chance the domain will be rejected
                if (!empty($this->options->domains['reject'])) {
                    foreach ($this->options->domains['reject'] as $rejectedDomain) {
                        if ($this->isDomainMatch($rejectedDomain, $urlHost)) {
                            $accepted = false;
                            break;
                        }
                    }
                }
                $this->domainAcceptedCache->put($urlHost, $accepted);
                return $accepted;
            });
    }

    /**
     *
     * $pattern is a full or partial domain name.
     * Examples:
     *  'www.example.com'
     *  '.example.'
     *  'www.example.'
     *  '.example.com'
     *  'example.com'
     *  'example.fr'
     *  '.fr'
     *  '.fr.'
     *
     * @param string $pattern partial domain name to look for
     * @param string $domain domain name from URL
     * @return bool
     */
    private function isDomainMatch(string $pattern, string $domain) : bool
    {
        foreach ([ltrim($pattern, '.'), $pattern] as $patt) {
            $dotLeft = ($patt[0] === '.');
            $dotRight = ($patt[-1] === '.');
            if ($dotLeft && $dotRight) {
                if (strpos($domain, $patt, 1)) {
                    return true;
                }
            } elseif ($dotLeft) {
                if (substr($domain, -strlen($patt)) === $patt) {
                    return true;
                }
            } elseif ($dotRight) {
                if (substr($domain, 0, strlen($patt)) === $patt) {
                    return true;
                }
            } else {
                if ($domain === $patt) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Filter excludes URLs that have a scheme that is not in the
     * list of accepted schemes.
     *
     * Does not exclude URLs with no scheme.
     *
     * @param Sequence $urls URLs to filter
     * @return Sequence filtered URLs
     */
    private function filterSchemes(Sequence $urls) : Sequence
    {
        if (empty($this->options->schemes)) {
            return $urls;
        }
        return $urls->filter(function ($url) {
            $scheme = $url->getPart('scheme');
            return (
                empty($scheme)
                || isset($this->options->schemes['accept'][$scheme])
                || !isset($this->options->schemes['reject'][$scheme])
            );
        });
    }

    /**
     * Returns an array URLs extracted from the HTML text.
     *
     * The retured array is not filtered, unless the distinctUrls option is true,
     * in which case the returned array only contains distinct urls.
     *
     * @param string $html the page to extract urls from
     * @param string $parts optional dot-separated string of urls segments to use @see UrlHelper::get($parts)
     * @return \Ds\Sequence extracted URLs
     */
    private function getAllUrls(string $html, string $parts) : \Ds\Sequence
    {
        $urls = [];
        // Prevent HTML errors from bubbling up.
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML($html);
        $doc->preserveWhiteSpace = false;
        // look for <base> tag and change context URL if necessary
        $baseTags = $doc->getElementsByTagName('base');
        if ($baseTags->length > 0 && $baseTags->item(0)->hasAttribute('href')) {
            $this->context = $baseTags->item(0)->getAttribute('href');
        }
        foreach ((new \DOMXPath($doc))->query($this->hrefQuery) as $href) {
            $url = (new UrlHelper($href->nodeValue))->getNormalised()->get($parts);
            if (!empty($this->options->distinctUrls)) {
                $urls[(string) $url] = $url;
            } else {
                $urls[] = $url;
            }
        }
        return new Vector(array_values($urls));
    }
}
