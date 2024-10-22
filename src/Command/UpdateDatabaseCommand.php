<?php

namespace App\Command;

use App\Service\LastfmApiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateDatabaseCommand extends Command
{
    protected static $defaultName = 'app:update-database';

    private LastfmApiService $lastfmApiService;

    public function __construct(LastfmApiService $lastfmApiService)
    {
        parent::__construct();
        $this->lastfmApiService = $lastfmApiService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Met à jour la base de données avec les données de l\'API Last.fm.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Mise à jour de la base de données...');

        try {
            $tags = $this->lastfmApiService->collectAllTags();
            $output->writeln(sprintf('La base de données a été mise à jour. %d tags ont été collectés.', count($tags)));
        } catch (\Exception $e) {
            $output->writeln(sprintf('Erreur lors de la mise à jour de la base de données : %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
