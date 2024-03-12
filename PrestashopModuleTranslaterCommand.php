<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PrestashopModuleTranslaterCommand extends Command
{
    const DEFAULT_FILTERS = [];

    /**
     * List of folders to exclude from the search
     *
     * @var array<int, string>
     */
    private $filters;

    protected function configure()
    {
        $this
            ->setName('adilis:translate')
            ->setDescription('Automatically add an "index.php" in all your directories or your zip file recursively')
            ->addArgument(
                'real_path',
                InputArgument::OPTIONAL,
                'The real path of your module'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $realPath = $input->getArgument('real_path');

        if ($realPath === false) {
            throw new \Exception('Could not get current directory. Check your permissions.');
        }

        new PrestashopModuleTranslaterService($realPath);

        return 0;
    }
}