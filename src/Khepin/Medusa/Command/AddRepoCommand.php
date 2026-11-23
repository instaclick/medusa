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
    protected $medusaConfig;
    protected $satisConfig;
    protected $satisVcsType;

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
        $this->output = $output;

        $this->medusaConfig = json_decode(file_get_contents($input->getArgument('config')));

        $satisFile = $this->medusaConfig ? new JsonFile($this->medusaConfig->satisconfig) : null;
        $this->satisConfig  = $satisFile ? $satisFile->read() : null;
        $this->satisVcsType = $this->getVcsType();

        $url = $this->getRepositoryUrl($package);

        if (!$url) {
            $this->mirrorPackagistAndRepositories($input->getOption('with-deps'),  $package);
        } else {
            $this->mirrorRepositoryOnly($package, $url);
        }

        if ($satisFile) {
            $this->satisConfig['repositories'] = $this->deduplicate($this->satisConfig['repositories']);

            $satisFile->write($this->satisConfig);
        }

        return 0;
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
        if ($this->satisConfig) {
            $satisUrl = $this->medusaConfig->satisurl;

            if ($satisUrl) {
                $url = $package.'.git';
                $repo = array(
                    'type' => $this->satisVcsType,
                    'url' => $satisUrl . '/' . $url
                );
            } else {
                $url = ltrim(realpath($this->medusaConfig->repodir.'/'.$package.'.git'), '/');
                $repo = array(
                    'type' => 'git',
                    'url' => 'file:///' . $url
                );
            }

            $this->satisConfig['repositories'][] = $repo;
        }
    }

    private function getVcsType()
    {
        if ($this->satisConfig &&
            isset($this->satisConfig['config']['gitlab-domains']) &&
            is_array($this->satisConfig['config']['gitlab-domains'])
        ) {
            $parts = parse_url($this->medusaConfig->satisurl);

            return isset($parts['host']) && in_array($parts['host'], $this->satisConfig['config']['gitlab-domains']) ? 'gitlab' : 'git';
        }

        return 'git';
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
        $outputDir = $this->medusaConfig->repodir;
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
        if (empty($this->medusaConfig->repositories)) {
            return null;
        }

        foreach ($this->medusaConfig->repositories as $repo) {
            if (property_exists($repo, 'name') && $repo->name === $package) {
                return $repo->url;
            }
        }

        return null;
    }
}
