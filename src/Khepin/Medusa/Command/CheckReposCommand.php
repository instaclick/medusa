<?php
/**
 * @copyright 2013 Sébastien Armand
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace Khepin\Medusa\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckReposCommand extends Command
{
    /**
     * @var \GuzzleHttp\Client
     */
    protected $guzzle;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    public function __construct()
    {
        parent::__construct();
        $this->guzzle = new Client(['base_uri' => 'https://packagist.org']);
    }

    protected function configure()
    {
        $this
            ->setName('check')
            ->setDescription('Check each mirrored package for problems')
            ->setDefinition(array(
                new InputArgument('config', InputArgument::OPTIONAL, 'A config file', 'medusa.json')
            ))
            ->setHelp(<<<EOT
The <info>check</info> command reads the given medusa.json file and checks
each mirrored git repository for problems.
EOT
            )
        ;
    }

    /**
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $config = json_decode(file_get_contents($input->getArgument('config')));
        $dir = $config->repodir;
        $repos = glob($dir.'/*/*.git');

        $fetchCmd = 'cd %s && git fetch --prune';
        $updateCmd = 'cd %s && git update-server-info -f';

        foreach ($repos as $repo) {
            $output->writeln(' - Checking for problems in <info>'.$repo.'</info>');

            $package = substr($repo, strlen($dir) + 1, -4);

            $this->checkRepository($repo, $package);
        }
    }

    protected function checkRepository($repoDir, $package)
    {
        try {
            $response = $this->guzzle->get('/packages/'.$package.'.json')->getBody()->getContents();
        } catch (\Exception $e) {
            $this->output->writeln(' - <info>' . $package . '</info> not found on packagist.org');

            return;
        }

        $packageInfo = json_decode($response);

        $url = $packageInfo->package->repository;

        $gitConfig = parse_ini_file($repoDir . '/config', true);

        if ($gitConfig['remote origin']['url'] !== $url) {
            $this->output->writeln(' - <info>' . $package . '</info> source repository has moved to ' . $url);
        }
    }
}
