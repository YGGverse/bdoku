<?php

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Check arguments
if (empty($argv[1]))
{
    exit(_('Configured hostname required as argument!') . PHP_EOL);
}

// Check cert exists
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/cert.pem'))
{
    exit(
        sprintf(
            _('Certificate for host "%s" not found!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Check key exists
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/key.rsa'))
{
    exit(
        sprintf(
            _('Key for host "%s" not found!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Check host configured
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/config.json'))
{
    exit(
        sprintf(
            _('Host "%s" not configured!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Check data directory exist
if (!is_dir(__DIR__ . '/../host/' . $argv[1] . '/data'))
{
    exit(
        sprintf(
            _('Data directory "%s" not found!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Init config
$config = json_decode(
    file_get_contents(
        __DIR__ . '/../host/' . $argv[1] . '/config.json'
    )
);

// Init memory
$memory = new \Yggverse\Cache\Memory(
    $config->memcached->server->host,
    $config->memcached->server->port,
    $config->memcached->server->host.
    $config->memcached->server->port,
    $config->memcached->server->timeout
);

$memory->flush();

// Init filesystem
$filesystem = new \Yggverse\Gemini\Dokuwiki\Filesystem(
    sprintf(
        __DIR__ . '/../host/' . $argv[1] . '/data'
    )
);

// Init reader
$reader = new \Yggverse\Gemini\Dokuwiki\Reader();

// Init helper
$helper = new \Yggverse\Gemini\Dokuwiki\Helper(
    $filesystem,
    $reader
);

// Init search server
$manticore = new \Manticoresearch\Client(
    [
        'host' => $config->manticore->server->host,
        'port' => $config->manticore->server->port,
    ]
);

// Init search index
$index = $manticore->index(
    $config->manticore->index->document->name
);

$index->drop(true);
$index->create(
    [
        'uri' =>
        [
            'type' => 'text'
        ],
        'name' =>
        [
            'type' => 'text'
        ],
        'data' =>
        [
            'type' => 'text'
        ]
    ],
    (array) $config->manticore->index->document->settings
);

foreach ($filesystem->getList() as $path)
{
    if (!str_ends_with($path, $config->manticore->index->extension))
    {
        continue;
    }

    if ($uri = $filesystem->getPageUriByPath($path))
    {
        if ($data = $filesystem->getDataByPath($path))
        {
            $gemini = $reader->toGemini(
                $data
            );

            $index->addDocument(
                [
                    'uri'  => $uri,
                    'name' => (string) $reader->getH1(
                        $gemini
                    ),
                    'data' => (string) $reader->toGemini(
                        $gemini
                    )
                ],
                crc32(
                    $uri
                )
            );
        }
    }
}

// Init server
$server = new \Yggverse\TitanII\Server();

$server->setCert(
    __DIR__ . '/../host/' . $argv[1] . '/cert.pem'
);

$server->setKey(
    __DIR__ . '/../host/' . $argv[1] . '/key.rsa'
);

$server->setHandler(
    function (\Yggverse\TitanII\Request $request): \Yggverse\TitanII\Response
    {
        global $config;
        global $memory;
        global $index;
        global $filesystem;
        global $reader;
        global $helper;

        $response = new \Yggverse\TitanII\Response();

        $response->setCode(
            $config->gemini->response->default->code
        );

        $response->setMeta(
            $config->gemini->response->default->meta
        );

        // Route begin
        switch ($request->getPath())
        {
            // Static routes
            case null:
            case false:
            case '':

                $response->setCode(
                    30
                );

                $response->setMeta(
                    sprintf(
                        'gemini://%s%s/',
                        $config->gemini->server->host,
                        $config->gemini->server->port == 1965 ? null : ':' . $config->gemini->server->port
                    )
                );

                return $response;

            break;

            case '/search':

                // Search query request
                if (empty($request->getQuery()))
                {
                    $response->setMeta(
                        'text/plain'
                    );

                    $response->setCode(
                        10
                    );

                    return $response;
                }

                // Prepare query
                $query = trim(
                    urldecode(
                        $request->getQuery()
                    )
                );

                // Do search
                $results = $index->search(
                    @\Manticoresearch\Utils::escape(
                        $query
                    )
                )->get();

                // Init page content
                $lines = [
                    PHP_EOL
                ];

                // Append page title
                $lines[] = sprintf(
                    '# %s - %s',
                    $config->string->search,
                    $query
                );

                // Append search results
                if ($total = $results->getTotal())
                {
                    $lines[] = null;
                    $lines[] = sprintf(
                        '%s: %d',
                        $config->string->found,
                        $total
                    );

                    $lines[] = null;
                    $lines[] = sprintf(
                        '## %s',
                        $config->string->results
                    );

                    $lines[] = null;
                    foreach($results as $result)
                    {
                        $lines[] = sprintf(
                            '=> /%s %s',
                            $result->get('uri'),
                            $result->get('name')
                        );
                    }
                }

                // Nothing found
                else
                {
                    $lines[] = null;
                    $lines[] = $config->string->nothing;
                }

                // Append actions
                $lines[] = null;
                $lines[] = sprintf(
                    '## %s',
                    $config->string->actions
                );

                // Append search link
                $lines[] = null;
                $lines[] = sprintf(
                    '=> /search %s',
                    $config->string->search
                );

                // Append homepage link
                $lines[] = sprintf(
                    '=> / %s',
                    $config->string->main
                );

                // Append source link
                $lines[] = null;
                $lines[] = sprintf(
                    '=> %s %s',
                    $config->dokuwiki->url->source,
                    $config->string->source
                );

                // Append about info
                $lines[] = $config->string->about;

                // Append aliases
                if ($config->dokuwiki->url->alias)
                {
                    $lines[] = null;
                    $lines[] = sprintf(
                        '## %s',
                        $config->string->alias
                    );

                    $lines[] = null;
                    foreach ($config->dokuwiki->url->alias as $base => $name)
                    {
                        $lines[] = sprintf(
                            '=> %s %s',
                            $base,
                            $name
                        );
                    }
                }

                // Build content
                $response->setContent(
                    implode(
                        PHP_EOL,
                        $lines
                    )
                );

                return $response;

            break;

            default:

                // Parse request
                preg_match('/^\/([^\/]*)$/', $request->getPath(), $matches);

                $uri = isset($matches[1]) ? $matches[1] : '';

                // File request, get page content
                if ($path = $filesystem->getPagePathByUri($uri))
                {
                    // Check for cached results
                    if ($content = $memory->get($path))
                    {
                        $response->setContent(
                            $content
                        );

                        return $response;
                    }

                    // Define base URL
                    $reader->setMacros(
                        '~URL:base~',
                        sprintf(
                            'gemini://%s%s/',
                            $request->getHost(),
                            $config->gemini->server->port == 1965 ? null : ':' . $config->gemini->server->port
                        )
                    );

                    // Define index menu
                    $menu = [];

                    // Append index sections
                    if ($sections = $helper->getChildrenSectionLinksByUri($uri))
                    {
                        // Append header
                        $menu[] = null;
                        $menu[] = sprintf(
                            '### %s',
                            $config->string->sections
                        );

                        // Append sections
                        $menu[] = null;
                        foreach ($sections as $section)
                        {
                            $menu[] = $section;
                        }
                    }

                    // Get children pages
                    if ($pages = $helper->getChildrenPageLinksByUri($uri))
                    {
                        // Append header
                        $menu[] = null;
                        $menu[] = sprintf(
                            '### %s',
                            $config->string->pages
                        );

                        // Append pages
                        $menu[] = null;
                        foreach ($pages as $page)
                        {
                            $menu[] = $page;
                        }
                    }

                    // Set macros value
                    if ($menu)
                    {
                        $reader->setRule(
                            '/\{\{indexmenu>:([^\}]+)\}\}/i',
                            implode(
                                PHP_EOL,
                                $menu
                            )
                        );
                    }

                    // Convert
                    $gemini = $reader->toGemini(
                        $filesystem->getDataByPath(
                            $path
                        )
                    );

                    $lines = [
                        $gemini
                    ];

                    // Get page links
                    if ($links = $reader->getLinks($gemini))
                    {
                        $lines[] = null;
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->links
                        );

                        $lines[] = null;
                        foreach ($links as $link)
                        {
                            $lines[] = sprintf(
                                '=> %s',
                                $link
                            );
                        }
                    }

                    // Append actions header
                    $lines[] = null;
                    $lines[] = sprintf(
                        '## %s',
                        $config->string->actions
                    );

                    // Append search link
                    $lines[] = null;
                    $lines[] = sprintf(
                        '=> /search %s',
                        $config->string->search
                    );

                    // Append homepage link
                    $lines[] = sprintf(
                        '=> / %s',
                        $config->string->main
                    );

                    // Append source link
                    $lines[] = null;
                    $lines[] = sprintf(
                        '=> %s/%s %s',
                        $config->dokuwiki->url->source,
                        $uri,
                        $config->string->source
                    );

                    // Append about info
                    $lines[] = $config->string->about;

                    // Append aliases
                    if ($config->dokuwiki->url->alias)
                    {
                        $lines[] = null;
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->alias
                        );

                        $lines[] = null;
                        foreach ($config->dokuwiki->url->alias as $base => $name)
                        {
                            $lines[] = sprintf(
                                '=> %s/%s %s',
                                $base,
                                $uri,
                                $name
                            );
                        }
                    }

                    // Merge lines
                    $content = implode(
                        PHP_EOL,
                        $lines
                    );

                    // Cache results
                    $memory->set(
                        $path,
                        $content
                    );

                    // Response
                    $response->setContent(
                        $content
                    );

                    return $response;
                }

                // File not found, request directory for minimal navigation
                else if ($path = $filesystem->getDirectoryPathByUri($uri))
                {
                    // Check for cached results
                    if ($content = $memory->get($path))
                    {
                        $response->setContent(
                            $content
                        );

                        return $response;
                    }

                    // Init home page content
                    $lines = [
                        PHP_EOL
                    ];

                    // Build header
                    $h1 = [];

                    $segments = [];

                    foreach ((array) explode(':', $uri) as $segment)
                    {
                        $segments[] = $segment;

                        // Find section index if exists
                        if ($file = $filesystem->getPagePathByUri(implode(':', $segments) . ':' . $segment))
                        {
                            $h1[] = $reader->getH1(
                                $reader->toGemini(
                                    $filesystem->getDataByPath(
                                        $file
                                    )
                                )
                            );
                        }

                        // Find section page if exists
                        else if ($file = $filesystem->getPagePathByUri(implode(':', $segments)))
                        {
                            $h1[] = $reader->getH1(
                                $reader->toGemini(
                                    $filesystem->getDataByPath(
                                        $file
                                    )
                                )
                            );
                        }

                        // Reset title of undefined segment
                        else
                        {
                            $h1[] = null;
                        }
                    }

                    // Append header
                    $lines[] = sprintf(
                        '# %s',
                        empty($h1[0]) ? $config->string->welcome : implode(' ', $h1)
                    );

                    // Get children sections
                    if ($sections = $helper->getChildrenSectionLinksByUri($uri))
                    {
                        // Append header
                        $lines[] = null;
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->sections
                        );

                        // Append sections
                        $lines[] = null;
                        foreach ($sections as $section)
                        {
                            $lines[] = $section;
                        }
                    }

                    // Get children pages
                    if ($pages = $helper->getChildrenPageLinksByUri($uri))
                    {
                        // Append header
                        $lines[] = null;
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->pages
                        );

                        // Append pages
                        $lines[] = null;
                        foreach ($pages as $page)
                        {
                            $lines[] = $page;
                        }
                    }

                    // Append about info
                    $lines[] = null;
                    $lines[] = sprintf(
                        '## %s',
                        $config->string->actions
                    );

                    // Append search link
                    $lines[] = null;
                    $lines[] = sprintf(
                        '=> /search %s',
                        $config->string->search
                    );

                    // Append source link
                    $lines[] = sprintf(
                        '=> %s %s',
                        $config->dokuwiki->url->source,
                        $config->string->source
                    );

                    // Append about info
                    $lines[] = $config->string->about;

                    // Append aliases
                    if ($config->dokuwiki->url->alias)
                    {
                        $lines[] = null;
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->alias
                        );

                        $lines[] = null;
                        foreach ($config->dokuwiki->url->alias as $base => $name)
                        {
                            $lines[] = sprintf(
                                '=> %s/%s %s',
                                $base,
                                $uri,
                                $name
                            );
                        }
                    }

                    // Merge lines
                    $content = implode(
                        PHP_EOL,
                        $lines
                    );

                    // Cache results
                    $memory->set(
                        $path,
                        $content
                    );

                    // Response
                    $response->setContent(
                        $content
                    );

                    return $response;
                }

                // Media request
                else if ($path = $filesystem->getMediaPathByUri($uri))
                {
                    if ($mime = $filesystem->getMimeByPath($path))
                    {
                        if ($data = $filesystem->getDataByPath($path))
                        {
                            // Set MIME
                            $response->setMeta(
                                $mime
                            );

                            // Append data
                            $response->setContent(
                                $data
                            );

                            // Response
                            return $response;
                        }
                    }
                }
        }

        // Not found
        $response->setCode(
            51
        );

        $response->setMeta(
            $config->string->nothing
        );

        return $response;
    }
);

// Start server
echo sprintf(
    _('Server "%s" started on %s:%d') . PHP_EOL,
    $argv[1],
    $config->gemini->server->host,
    $config->gemini->server->port
);

$server->start(
  $config->gemini->server->host,
  $config->gemini->server->port
);