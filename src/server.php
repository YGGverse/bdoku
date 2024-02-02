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

// Init filesystem
$filesystem = new \Yggverse\Gemini\Dokuwiki\Filesystem(
    sprintf(
        __DIR__ . '/../host/' . $argv[1] . '/data'
    )
);

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
        global $filesystem;

        $response = new \Yggverse\TitanII\Response();

        $response->setCode(
            20
        );

        $response->setMeta(
            'text/gemini'
        );

        // Route begin
        switch ($request->getPath())
        {
            // Static route here
            case null:
            case false:
            case '':

                // @TODO redirect to document root (/)

            break;

            case '/search':

                // @TODO implement search feature

            break;

            default:

                // Parse request
                preg_match('/^\/([^\/]*)$/', $request->getPath(), $matches);

                $_uri = isset($matches[1]) ? $matches[1] : '';

                // File request, get page content
                if ($path = $filesystem->getPagePathByUri($_uri))
                {
                    // Check for cached results
                    if ($content = $memory->get($path))
                    {
                        $response->setContent(
                            $content
                        );

                        return $response;
                    }

                    // Init reader
                    $reader = new \Yggverse\Gemini\Dokuwiki\Reader();

                    // Define base URL
                    $reader->setMacros(
                        '~URL:base~',
                        sprintf(
                            'gemini://%s%s/%s',
                            $config->gemini->server->host,
                            $config->gemini->server->port == 1965 ? null : ':' . $config->gemini->server->port,
                            '' // @TODO append relative prefix (:)
                        )
                    );

                    // Define index menu
                    /* @TODO
                    $pages = [];

                    if ($directory = $filesystem->getDirectoryPathByUri($_uri))
                    {
                        foreach ($filesystem->getPagePathsByPath($directory) as $file)
                        {
                            $pages[] = sprintf(
                                '=> /%s',
                                $filesystem->getPageUriByPath(
                                    $file
                                )
                            );
                        }
                    }

                    if ($pages)
                    {
                        $reader->setRule(
                            '/\{\{indexmenu>:([^\}]+)\}\}/i',
                            implode(
                                PHP_EOL,
                                $pages
                            )
                        );
                    }
                    */

                    // Convert
                    $gemini = $reader->toGemini(
                        file_get_contents(
                            $path
                        )
                    );

                    $lines = [
                        $gemini
                    ];

                    // Get page links
                    if ($links = $reader->getLinks($gemini))
                    {
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->links
                        );

                        foreach ($links as $link)
                        {
                            $lines[] = sprintf(
                                '=> %s',
                                $link
                            );
                        }
                    }

                    // Append actions header
                    $lines[] = sprintf(
                        '## %s',
                        $config->string->actions
                    );

                    // Append source and homepage link
                    $lines[] = sprintf(
                        '=> gemini://%s%s %s',
                        $config->gemini->server->host,
                        $config->gemini->server->port == 1965 ? null : ':' . $config->gemini->server->port,
                        $config->string->main
                    );

                    // Append source link
                    $lines[] = sprintf(
                        '=> %s/%s %s',
                        $config->dokuwiki->url->source,
                        $matches[1],
                        $config->string->source
                    );

                    // Append about info
                    $lines[] = $config->string->about;

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
                else if ($directory = $filesystem->getDirectoryPathByUri($_uri))
                {
                    // Check for cached results
                    /*
                    if ($content = $memory->get('/'))
                    {
                        $response->setContent(
                            $content
                        );

                        return $response;
                    }
                    */

                    // Init reader
                    $reader = new \Yggverse\Gemini\Dokuwiki\Reader();

                    // Init home page content
                    $lines = [
                        PHP_EOL
                    ];

                    // Build header
                    $h1 = [];

                    $segments = [];

                    foreach ((array) explode(':', $_uri) as $segment)
                    {
                        $segments[] = $segment;

                        // Find section index if exists
                        if ($file = $filesystem->getPagePathByUri(implode(':', $segments) . ':' . $segment))
                        {
                            $h1[] = $reader->getH1(
                                $reader->toGemini(
                                    file_get_contents(
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
                                    file_get_contents(
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
                        empty($h1[0]) ? $config->string->welcome : implode(' - ', $h1)
                    );

                    // Get children sections
                    $sections = [];

                    foreach ($filesystem->getTree() as $path => $files)
                    {
                        if (str_starts_with($path, $directory) && $path != $directory)
                        {
                            // Init link name
                            $alt = null;

                            // Init this directory URI
                            $uri = $filesystem->getDirectoryUriByPath(
                                $path
                            );

                            // Skip sections deeper this level
                            if (substr_count($uri, ':') > ($_uri ? substr_count($_uri, ':') + 1 : 0))
                            {
                                continue;
                            }

                            // Get section names
                            $segments = [];

                            foreach ((array) explode(':', $uri) as $segment)
                            {
                                $segments[] = $segment;

                                // Find section index if exists
                                if ($file = $filesystem->getPagePathByUri(implode(':', $segments) . ':' . $segment))
                                {
                                    $alt = $reader->getH1(
                                        $reader->toGemini(
                                            file_get_contents(
                                                $file
                                            )
                                        )
                                    );
                                }

                                // Find section page if exists
                                else if ($file = $filesystem->getPagePathByUri(implode(':', $segments)))
                                {
                                    $alt = $reader->getH1(
                                        $reader->toGemini(
                                            file_get_contents(
                                                $file
                                            )
                                        )
                                    );
                                }

                                // Reset title of undefined segment
                                else
                                {
                                    $alt = null;
                                }
                            }

                            // Register section link
                            $sections[] = sprintf(
                                '=> /%s %s',
                                $uri,
                                $alt
                            );
                        }
                    }

                    // Append sections
                    if ($sections)
                    {
                        // Keep unique
                        $sections = array_unique(
                            $sections
                        );

                        // Sort asc
                        sort(
                            $sections
                        );

                        // Append header
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->sections
                        );

                        // Append sections
                        foreach ($sections as $section)
                        {
                            $lines[] = $section;
                        }
                    }

                    // Get children pages
                    $pages = [];

                    foreach ($filesystem->getPagePathsByPath($directory) as $file)
                    {
                        $pages[] = sprintf(
                            '=> /%s %s',
                            $filesystem->getPageUriByPath(
                                $file
                            ),
                            $reader->getH1(
                                $reader->toGemini(
                                    file_get_contents(
                                        $file
                                    )
                                )
                            )
                        );
                    }

                    if ($pages)
                    {
                        // Keep unique
                        $pages = array_unique(
                            $pages
                        );

                        // Sort asc
                        sort(
                            $pages
                        );

                        // Append header
                        $lines[] = sprintf(
                            '## %s',
                            $config->string->pages
                        );

                        // Append pages
                        foreach ($pages as $page)
                        {
                            $lines[] = $page;
                        }
                    }

                    // Append about info
                    $lines[] = sprintf(
                        '## %s',
                        $config->string->resources
                    );

                    // Append source link
                    $lines[] = sprintf(
                        '=> %s %s',
                        $config->dokuwiki->url->source,
                        $config->string->source
                    );

                    // Append about info
                    $lines[] = $config->string->about;

                    // Merge lines
                    $content = implode(
                        PHP_EOL,
                        $lines
                    );

                    // @TODO '~index:menu~'

                    // Cache results
                    $memory->set(
                        '/',
                        $content
                    );

                    // Response
                    $response->setContent(
                        $content
                    );

                    return $response;
                }
        }

        // Route not found
        $response->setCode(
            51
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