<?php
/**
 * @copyright 2013 Sébastien Armand
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace Khepin\Medusa;

use Symfony\Component\Process\Process;

class Downloader
{
    protected $url;

    protected $package;

    public function __construct($package, $url)
    {
        $this->package = $package;
        $this->url     = $url;
    }

    public function download($in_dir)
    {
        $repo = $in_dir . '/' . $this->package . ".git";

        if (is_dir($repo)) {
            return;
        }

        $url = preg_replace('~^git@github.com:~', 'git://github.com/', $this->url);

        $process = new Process(['git', 'clone', '--mirror', $url, $repo]);

        $this->mirror($process);

        if (!$process->isSuccessful()) {
            // retry as https in case it was a spurious authentication error
            if (strpos($url, 'git://github.com/') === 0) {
                $url = preg_replace('~^git:~', 'https:', $url);

                $process = new Process(['git', 'clone', '--mirror', $url, $repo]);

                $this->mirror($process);
            }

            if (!$process->isSuccessful()) {
                throw new \Exception($process->getErrorOutput());
            }
        }

        $process = new Process(['git', 'update-server-info', '-f'], $repo);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception($process->getErrorOutput());
        }

        $process = new Process(['git', 'config', 'http.receivepack', 'false'], $repo);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception($process->getErrorOutput());
        }
    }

    private function mirror($process)
    {
        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) use ($process) {
            // terminate process if credentials required
            if ($type === Process::OUT && str_contains($buffer, 'Username for')) {
                $process->stop();
            }
        });
    }
}
