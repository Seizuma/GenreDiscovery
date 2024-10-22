<?php

namespace App\Command;

use App\Service\LastfmApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update-database',
    description: 'Met à jour la base de données avec les données de l\'API Last.fm.'
)]
class UpdateDatabaseCommand extends Command
{
    private LastfmApiService $lastfmApiService;

    public function __construct(LastfmApiService $lastfmApiService)
    {
        parent::__construct();

        $this->lastfmApiService = $lastfmApiService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Mise à jour de la base de données...');

        // Appeler les méthodes pour collecter les données
        $this->lastfmApiService->collectAllTags();

        $output->writeln('La base de données a été mise à jour.');

        return Command::SUCCESS;
    }
}
