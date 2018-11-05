<?php
$config = [
    'db' => [
            'mongodb' => [
                'host' => '192.168.1.192',
                'port' => '27017',
                'database' => 'trawler',
                'username' => 'trawler',
                'password' => 'salmon'
                // 'password' => 'pEIQaEDM3XohsrzKYK80iEdt'
            ],
            'collections' => [
                'hosts' => 'trawlerHosts',
                'pages' => 'trawlerPages',
                'microdata' => 'microdata'
            ],
            'hostsCollection' => 'trawlerHosts',
            'pagesCollection' => 'trawlerPages',
            'microdataCollection' => 'microdata'
    ],
    'fetch' => [
        'hostsService' => [
            'crawlDelay' => 30 * 1000, // (milliseconds)
            'hostRefreshPeriod' => 24 * 60 * 60 * 1000, // (milliseconds) period between refreshes for a host
            'maxKnownHosts' => 4000 // (items) maximum size of in-memory hostnames cache
        ],
        'pagesService' => [
            'batchSize' => 20,
            'maxPendingBulkWrites' => 1,
            'maxKnownUrls' => 0,
            'preloadKnownUrls' => false
        ],
        'http' => [
            'request' => [
                'timeout' => 10,
                'headers' => ['User-Agent' => 'Trawler/1.0']
            ],
            'response' => [
                'acceptContentTypes' => [
                    'text/html',
                    'application/xhtml+xml',
                    'application/xml'
                ],
                'acceptLanguages' => ['en']
            ]
        ]
    ],
    'scrape' => [
        'hostsService' => [
            'crawlDelay' => 20 * 1000, // (milliseconds)
            'hostRefreshPeriod' => 24 * 60 * 60 * 1000, // (milliseconds) period between refreshes for a host
            'maxKnownHosts' => 4000 // (items) maximum size of in-memory hostnames cache
        ],
        'pagesService' => [
            'batchSize' => 1,
            'maxPendingBulkWrites' => 200,
            'maxKnownUrls' => 200000,
            'preloadKnownUrls' => true
        ],
        'urlFilters' => [
            'extensions' => [
                'accept' => ['html', 'htm', 'php', 'aspx', 'jsp'],
                'reject' => ['js', 'css', 'jpg', 'png', 'jpeg', 'gif', 'pdf', 'doc',
                    'docx', 'xls', 'xlsx', 'exe', 'deb', 'zip', 'gz', '7z', 'xml', 'rss',
                    'wma', 'ogg', 'mp3', 'mp4', 'xvid', 'divx', 'avi', 'jsx',
                    'jar', 'tiff', 'mpeg', 'ico']
            ],
            'domains' => [
                'accept' => ['.nz', '.kiwi'],
                'reject' => ['.instagram.', '.twitter.', '.facebook.', '.youtube.', '.google.', '.ebay.',
                    '.amazon.', '.mozilla.', '.microsoft.', '.govt.', '.googleapis.', '.gstatic.', '.apple.',
                    '.cloudflare.', '.android.', '.goo.gl', '.pinterest.', '.flickr.', '.tumblr.',
                    '.youku.', '.urbandictionary.', '.gettyimages.']
            ],
            'schemes' => ['accept' => ['http', 'https']],
            'distinctUrls' => true
        ]
    ]
];

if ($trawlerInitialise ?? false) {
    echo 'initialising...';
    $config['fetch']['pagesService']['batchSize'] = 1;
    $config['scrape']['pagesService']['batchSize'] = 1;
    $config['scrape']['pagesService']['maxPendingBulkWrites'] = 1;
    $config['scrape']['pagesService']['preloadKnownUrls'] = false;
}
