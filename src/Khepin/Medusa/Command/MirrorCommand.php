<?php
/**
 * @copyright 2013 Sébastien Armand
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace Khepin\Medusa\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Khepin\Medusa\DependencyResolver;
use Guzzle\Service\Client;
use Symfony\Component\Console\Input\ArrayInput;

class MirrorCommand extends Command
{
    protected $guzzle;

    protected function configure()
    {
        $this
            ->setName('mirror')
            ->setDescription('Mirrors all repositories given a config file')
            ->setDefinition(array(
                new InputArgument('config', InputArgument::OPTIONAL, 'A config file', 'medusa.json')
            ))
            ->setHelp(<<<EOT
The <info>mirror</info> command reads the given medusa.json file and mirrors
the git repository for each package (including dependencies), so they can be used locally.
<warning>This will only work for repos hosted on github.com.</warning>
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
        $output->writeln('<info>First getting all dependencies</info>');
        $this->guzzle = new Client('http://packagist.org');
        $medusaConfig = $input->getArgument('config');
        $config = json_decode(file_get_contents($medusaConfig));
        $repos = array();

	if (!$config) {
            throw new \Exception($medusaConfig . ': invalid json configuration');
        }

        foreach ($config->require as $dependency) {
            $output->writeln(' - Getting dependencies for <info>'.$dependency.'</info>');
            $resolver = new DependencyResolver($dependency);
            $deps = $resolver->resolve();
            $repos = array_merge($repos, $deps);
        }

        foreach ($config->repositories as $repository) {
            if (property_exists($repository, 'name')) {
                $repos[] = $repository->name;
            }
        }

        $repos = array_unique($repos);

        $output->writeln('<info>Create mirror repositories</info>');

        foreach ($repos as $repo) {
            $command = $this->getApplication()->find('add');

            $arguments = array(
                'command'     => 'add',
                'package'     => $repo,
                'config'      => $medusaConfig,
            );

            $input = new ArrayInput($arguments);
            $returnCode = $command->run($input, $output);
        }
    }
}
