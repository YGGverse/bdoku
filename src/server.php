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
            // Home request
            case null:
            case '/':

                if ($path = $filesystem->getPagePathByUri($config->dokuwiki->uri->home))
                {
                    $reader = new \Yggverse\Gemini\Dokuwiki\Reader();

                    $response->setContent(
                        $reader->toGemini(
                            file_get_contents(
                                $path
                            )
                        )
                    );

                    return $response;
                }

            // Internal page request
            default:

                if (preg_match('/^\/([^\/]*)$/', $request->getPath(), $matches))
                {
                    if (!empty($matches[1]))
                    {
                        if ($path = $filesystem->getPagePathByUri($matches[1]))
                        {
                            $lines = [];

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

                            // Merge data lines
                            $data = implode(
                                PHP_EOL,
                                $lines
                            );

                            // Read document
                            $reader = new \Yggverse\Gemini\Dokuwiki\Reader();

                            $response->setContent(
                                $reader->toGemini(
                                    file_get_contents(
                                        $path
                                    ) . $data
                                )
                            );

                            return $response;
                        }
                    }
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