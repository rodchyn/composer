<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;

/**
 * @author Jérémy Romey <jeremy@free-agent.fr>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RequireCommand extends InitCommand
{
    protected function configure()
    {
        $this
            ->setName('require')
            ->setDescription('Adds required packages to your composer.json and installs them')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Required package with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
            ))
            ->setHelp(<<<EOT
The require command adds required packages to your composer.json and installs them

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $factory = new Factory;
        $file = $factory->getComposerFile();

        if (!file_exists($file)) {
            $output->writeln('<error>'.$file.' not found.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $output->writeln('<error>'.$file.' is not readable.</error>');

            return 1;
        }

        $dialog = $this->getHelperSet()->get('dialog');

        $json = new JsonFile($file);
        $composer = $json->read();

        $requirements = $this->determineRequirements($input, $output, $input->getArgument('packages'));

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $baseRequirements = array_key_exists($requireKey, $composer) ? $composer[$requireKey] : array();
        $requirements = $this->formatRequirements($requirements);

        if (!$this->updateFileCleanly($json, $baseRequirements, $requirements, $requireKey)) {
            foreach ($requirements as $package => $version) {
                $baseRequirements[$package] = $version;
            }

            $composer[$requireKey] = $baseRequirements;
            $json->write($composer);
        }

        $output->writeln('<info>'.$file.' has been updated</info>');

        // Update packages
        $composer = $this->getComposer();
        $io = $this->getIO();
        $install = Installer::create($io, $composer);

        $install
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($input->getOption('prefer-source'))
            ->setDevMode($input->getOption('dev'))
            ->setUpdate(true)
            ->setUpdateWhitelist($requirements);
        ;

        return $install->run() ? 0 : 1;
    }

    private function updateFileCleanly($json, array $base, array $new, $requireKey)
    {
        $contents = file_get_contents($json->getPath());

        $manipulator = new JsonManipulator($contents);

        foreach ($new as $package => $constraint) {
            if (!$manipulator->addLink($requireKey, $package, $constraint)) {
                return false;
            }
        }

        file_put_contents($json->getPath(), $manipulator->getContents());

        return true;
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        return;
    }
}
