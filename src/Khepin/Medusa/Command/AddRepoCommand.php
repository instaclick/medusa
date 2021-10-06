<?php
/**
 * @copyright 2013 Sébastien Armand
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace Khepin\Medusa\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Composer\Json\JsonFile;
use Khepin\Medusa\DependencyResolver;
use Khepin\Medusa\Downloader;

class AddRepoCommand extends Command
{
    protected $guzzle;
    protected $config;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct()
    {
        parent::__construct();
        $this->guzzle = new Client(['base_uri' => 'https://packagist.org']);
    }

    protected function configure()
    {
        $this
            ->setName('add')
            ->setDescription('Add a package to satis')
            ->setDefinition(array(
                new InputOption('with-deps', null, InputOption::VALUE_NONE, 'If set, the package dependencies will be downloaded too'),
                new InputArgument('package', InputArgument::REQUIRED, 'The name of a composer package', null),
                new InputArgument('config', InputArgument::OPTIONAL, 'A config file', 'medusa.json')
            ))
        ;
    }

    /**
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $package = $input->getArgument('package');
        $this->config = json_decode(file_get_contents($input->getArgument('config')));
        $this->output = $output;

        $url = $this->getRepositoryUrl($package);

        if (!$url) {
            $this->mirrorPackagistAndRepositories($input->getOption('with-deps'),  $package);
        } else {
            $this->mirrorRepositoryOnly($package, $url);
        }
    }

    protected function mirrorRepositoryOnly($package, $url)
    {
        $this->output->writeln(' - Mirroring <info>'.$package.'</info>');
        $this->getGitRepo($package, $url);
        $this->output->writeln('');

        $this->updateSatisConfig($package);
    }

    protected function mirrorPackagistAndRepositories($withDependencies, $package)
    {
        $deps = array($package);

        if ($withDependencies) {
            $resolver = new DependencyResolver($package);
            $deps = $resolver->resolve();
        }

        foreach ($deps as $package) {
            $this->output->writeln(' - Mirroring <info>'.$package.'</info>');
            $this->getGitRepo($package);
            $this->output->writeln('');

            $this->updateSatisConfig($package);
        }
    }

    protected function updateSatisConfig($package)
    {
        $satisConfig = $this->config->satisconfig;
        $satisUrl = $this->config->satisurl;

        if ($satisConfig) {
            $file = new JsonFile($satisConfig);
            $config = $file->read();

            if ($satisUrl) {
                $url = $package.'.git';
                $repo = array(
                    'type' => 'git',
                    'url' => $satisUrl . '/' . $url
                );
            } else {
                $url = ltrim(realpath($this->config->repodir.'/'.$package.'.git'), '/');
                $repo = array(
                    'type' => 'git',
                    'url' => 'file:///' . $url
                );
            }

            $config['repositories'][] = $repo;
            $config['repositories'] = $this->deduplicate($config['repositories']);
            $file->write($config);
        }
    }

    private function deduplicate($repositories)
    {
        $newRepositories = array();

        foreach ($repositories as $repository) {
            $newRepositories[$repository['url']] = $repository;
        }

        return array_values($newRepositories);
    }

    protected function getGitRepo($package, $url = null)
    {
        $outputDir = $this->config->repodir;
        $dir = $outputDir.'/'.$package.'.git';

        if (is_dir($dir)) {
            $this->output->writeln('  <comment>The repo already exists. Try updating it instead.</comment>');

            return;
        }

        if (!$url) {
            $response = $this->guzzle->get('/packages/'.$package.'.json')->getBody()->getContents();
            $packageInfo = json_decode($response);

            $package = $packageInfo->package->name;
            $url = $packageInfo->package->repository;
        }

        $downloader = new Downloader($package, $url);
        $downloader->download($outputDir);
    }

    /**
     * Get repository URL override
     *
     * @param string $package
     *
     * @return string|null
     */
    protected function getRepositoryUrl($package)
    {
        if (empty($this->config->repositories)) {
            return null;
        }

        foreach ($this->config->repositories as $repo) {
            if (property_exists($repo, 'name') && $repo->name === $package) {
                return $repo->url;
            }
        }

        return null;
    }
}
