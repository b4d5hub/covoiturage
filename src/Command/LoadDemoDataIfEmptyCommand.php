<?php

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Premier démarrage : charge les données de démonstration dans une base encore vide.
 * Une base qui contient déjà des comptes n'est jamais modifiée.
 */
#[AsCommand(name: 'app:demo-data:load-if-empty', description: 'Charge les données de démonstration si la base ne contient aucun compte')]
final class LoadDemoDataIfEmptyCommand extends Command
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->users->count([]) > 0) {
            $io->note('La base contient déjà des comptes : les données existantes sont conservées.');

            return Command::SUCCESS;
        }

        $fixtures = new ArrayInput([]);
        $fixtures->setInteractive(false);
        $result = $this->getApplication()->find('doctrine:fixtures:load')->run($fixtures, $output);

        if (Command::SUCCESS === $result) {
            $io->success('Données de démonstration chargées.');
        }

        return $result;
    }
}
