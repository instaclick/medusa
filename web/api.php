<?php
/**
 * Emulate Gitlab REST API Endpoints
 */

if (!isset($_SERVER['PATH_INFO'])) {
    http_response_code(404);
    die('Error: missing PATH_INFO');
}

checkPath($_SERVER['PATH_INFO']);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (preg_match('~^/api/v4/projects/([^/]+)([^?]*)~', $_SERVER['REQUEST_URI'], $matches)) {
            $path = urldecode($matches[1]);

            checkPath($path);

            if (!file_exists(__DIR__ . "/{$path}.git")) {
                 http_response_code(404);
                 die("Warn: repository not found {$path}");
            }

            $gitDir = escapeshellarg(__DIR__ . "/{$path}.git");

            /**
             * Retrieve a project
             *
             * @link https://docs.gitlab.com/api/projects/#retrieve-a-project
             */
            if ($matches[2] === '') {
                $defaultBranch = preg_match('~^  HEAD branch: (.*)$~m', shell_exec("git --git-dir={$gitDir} remote show origin"), $matches)
                    ? $matches[1]
                    : 'unknown';

                $protocol = isHttps() ? 'https://' : 'http://';
                $port = in_array($port = getServerPort(), [80, 443]) ? '' : ":{$port}";
                $url = "{$protocol}{$_SERVER['HTTP_HOST']}{$port}";

                header('Content-Type: application/json');

                $response = [
                    'default_branch' => "{$defaultBranch}",
                    'http_url_to_repo' => "{$url}/{$path}.git",
                    'path_with_namespace' => "{$path}",
                    'web_url' => "{$url}?ref=",
                ];

                echo json_encode($response);
                exit(0);
            }

            /**
             * Tags
             *
             * @link https://docs.gitlab.com/api/tags/
             */
            if ($matches[2] === '/repository/tags') {
                $format = escapeshellarg('%(refname:short)|%(*objectname)|%(objectname)|%(creatordate:iso-strict)');
                $raw = (string) shell_exec("git --git-dir={$gitDir} for-each-ref --format={$format} refs/tags 2>&1");
                $tags = [];

                foreach (explode("\n", trim($raw)) as $line) {
                    if (!$line) {
                        continue;
                    }

                    [$name, $derefSha, $objSha, $date] = explode('|', $line);

                    if (!isValidVersionTag($name)) {
                        continue;
                    }

                    $commitSha = $derefSha !== '' ? $derefSha : $objSha;

                    if ($commitSha === '') {
                        continue;
                    }

                    $tags[] = [
                        'name' => $name,
                        'commit' => [
                            'id' => $commitSha,
                            'committed_date' => $date !== '' ? $date : '2026-01-01T00:00:00Z',
                        ]
                    ];
                }

                header('Content-Type: application/json');
                header('Link:');
                echo json_encode($tags);
                exit(0);
            }

            /**
             * Branches
             *
             * @link https://docs.gitlab.com/api/branches/
             */
            if ($matches[2] === '/repository/branches') {
                $format = escapeshellarg('%(refname:short)|%(objectname)|%(committerdate:iso-strict)');
                $raw = (string) shell_exec("git --git-dir={$gitDir} for-each-ref --format={$format} refs/heads 2>&1");
                $branches = [];

                foreach (explode("\n", trim($raw)) as $line) {
                    if (!$line) {
                        continue;
                    }

                    [$name, $sha, $date] = explode('|', $line);

                    $branches[] = [
                        'name' => $name,
                        'commit' => [
                            'id' => $sha,
                            'committed_date' => $date !== '' ? $date : '2026-01-01T00:00:00Z',
                        ]
                    ];
                }

                header('Content-Type: application/json');
                header('Link:');
                echo json_encode($branches);
                exit(0);
            }

            /**
             * Retrieve a file from repository
             *
             * @link https://docs.gitlab.com/api/repository_files/
             */
            if (preg_match('~^/repository/files/([^/]*)(.*)$~', $matches[2], $matches2)) {
                $file = urldecode($matches2[1]);
                $raw = $matches2[2] === '/raw';

                checkPath($file);

                $ref = $_GET['ref'] ?? 'HEAD';

                if (!preg_match('/^[a-zA-Z0-9._\/-]+$/', $ref)) {
                    http_response_code(400);
                    exit(0);
                }

                $escRef = escapeshellarg($ref);
                $escFile = escapeshellarg($file);
                exec("git --git-dir={$gitDir} show {$escRef}:{$escFile}", $outputLines, $exitCode);

                if ($exitCode) {
                    http_response_code(404);
                    die("Info: file not found {$file} for {$ref}");
                }

                $content = implode("\n", $outputLines);

                if ($raw) {
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $content;
                    exit(0);
                }

                header('Content-Type: application/json');

                echo json_encode([
                  'file_name' => $file,
                  'file_path' => $path,
                  'encoding'  => 'base64',
                  'content'   => base64_encode($content),
                ]);

                exit(0);
            }

            /**
             * Retrieve file archive from repository
             *
             * @link https://docs.gitlab.com/api/repositories/#retrieve-file-archive-from-a-repository
             */
            if (preg_match('~^/repository/archive(\.(.+))?$~', $matches[2], $matches2)) {
                if (!isset($_GET['sha'])) {
                    http_response_code(400);
                    die('Error: commit SHA to download is mandatory');
                }

                $sha = $_GET['sha']; // gitlab would default to the tip of the default branch
                $format = $matches2[2] ?? 'tar.gz'; // gitlab default

                $filename = str_replace('/', '-', substr($path, strlen('repositories/'))) . "-{$sha}.{$format}";
                $distFile = __DIR__ . "/dist/{$filename}";

                if (!file_exists($distFile)) {
                    createArchive($gitDir, $distFile, $sha);
                }

                if (!file_exists($distFile) || !filesize($distFile)) {
                    http_response_code(500);
                    die("Error: unable to generate dist file {$filename}");
                }

                touch($distFile);

                if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
                    header('HTTP/1.1 304 Not Modified');
                    header('ETag: ' . trim($_SERVER['HTTP_IF_NONE_MATCH']));
                    exit(0);
                }

                download($distFile, $filename, $format);
                exit(0);
            }

            http_response_code(404);
            var_dump($matches);
            die("Warn: suspicious API request {$requestPath}");
        }

        http_response_code(400);
        die("Warn: unknown API request {$requestPath}");

    default:
        header('Allow: GET', true, 405);
        exit(0);
}

function checkPath($path)
{
    if ($path === '/' || strpos($path, '..') !== false || strpos($path, '/.') !== false) {
        http_response_code(400);
        die("Warn: suspicious path {$path}");
    }
}

function isValidVersionTag(string $name): bool
{
    return (bool) preg_match('/^v?\d+(\.\d+){1,3}(?:-(?:dev|alpha|a|beta|b|RC|rc|patch|p)\.?\d*)?(?:\+[a-zA-Z0-9.-]+)?$/i', $name);
}

function isHttps(): bool
{
    // Standard HTTPS check (Apache, Nginx, IIS)
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    // Check for reverse proxies and load balancers (e.g., Cloudflare, AWS)
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    // Fallback check for standard HTTPS port
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    return false;
}

function getServerPort(): int
{
    // Check for reverse proxy / load balancer port first
    if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
        // Handle comma-separated lists if multiple proxies exist
        $ports = explode(',', $_SERVER['HTTP_X_FORWARDED_PORT']);

        return (int) trim($ports[0]);
    }

    // Fallback to the standard server port
    if (!empty($_SERVER['SERVER_PORT'])) {
        return (int) $_SERVER['SERVER_PORT'];
    }

    // Default fallback based on protocol
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';

    return $isHttps ? 443 : 80;
}

/**
 * Create an archive of files from a named tree
 *
 * @link https://git-scm.com/docs/git-archive
 */
function createArchive($gitDir, $outputFile, $treeish)
{
    // format is inferred from the dist file extension
    $output = null;
    if (exec("git --git-dir={$gitDir} archive -o {$outputFile} {$treeish}", $output, $resultCode) === false || $resultCode) {
        http_response_code(500);
        die("Error: occurred while generating {$outputFile} ({$resultCode})");
    }
}

function download($distFile, $filename, $format)
{
    $chunkSize = 65536; // 64 KB chunks

    @ini_set('zlib.output_compression', 0);
    @ini_set('output_buffering', 'Off');
    @ini_set('implicit_flush', 1);

    for ($i = 0; $i < ob_get_level(); $i++) {
        @ob_end_clean();
    }

    switch($format) {
        case 'tar':
            header('Content-Type: application/x-tar');
            break;

        case 'tar.gz':
        case 'tgz':
            header('Content-Type: application/gzip');
            break;

        case 'zip':
        default:
            header('Content-Type: application/zip');
    }

    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Content-Length: ' . filesize($distFile));
    header('Accept-Ranges: bytes');
    header('X-Accel-Buffering: no');
    header('ETag: "' . md5(filemtime($distFile) . '-' . filesize($distFile)) . '"');

    if (!($handle = fopen($distFile, 'rb'))) {
        http_response_code(500);
        die("Error: unable to open {$distFile} for reading");
    }

    while (!feof($handle)) {
        echo fread($handle, $chunkSize);

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();

        if (connection_status() !== CONNECTION_NORMAL) {
            break;
        }
    }

    fclose($handle);
}
