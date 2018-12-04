# Trawler

Trawler does non-stop web crawling and scraping. It is designed to traverse the web quickly and broadly. It does not guarantee complete crawling of sites. The maximum number of pages fetched per site can be configured to between 4 and around 100,000, depending on the resources of the worker host.
Trawler uses MongoDB to maintain the state of the system while the work is done by Worker scripts.

## Workers
Trawler uses three types of command line workers; Fetchers, Scrapers, and Host fetchers. Many workers can be run concurrently on different hosts, needing only a connection to the shared MongoDB server.
There is also a very basic CLI reporting script in trawler-report.php.

### Fetcher
Fetchers download web pages and store them. They provide continuous fetching while respecting a crawl delay on each site. This is achieved by cycling through a batch of web hosts. The crawl delay and batch size are configurable.

### Scraper
Trawler comes with a URL scraper and a microformats/RDF scraper. The **URL scraper** feeds discovered URLs back into the Pages collection. The **microdata scraper** is an example of the type of scraper you can add to the system. It stores things in it's own collection.

You can add your own scrapers by implementing the Scraper interface and adding your object to scraper runner.

### Host fetcher
Host fetchers download robots.txt for each host or site. In the future it may also download sitemap.xml file. A site will not be crawled until a host fetcher has attempted to download its robots.txt.
