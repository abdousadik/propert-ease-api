<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:projects:assign-owner', description: 'Assign all currently unowned legacy projects to an explicitly selected existing user.')]
final class AssignProjectOwnerCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Existing owner email; this affects only projects whose owner_id is NULL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim($input->getArgument('email')));
        $userId = $this->connection->fetchOne('SELECT id FROM user WHERE email = ?', [$email]);
        if ($userId === false) {
            $io->error('No existing user with this email.');
            return Command::FAILURE;
        }
        $count = $this->connection->executeStatement('UPDATE project SET owner_id = ? WHERE owner_id IS NULL', [$userId]);
        $io->success(sprintf('Assigned %d unowned projects to %s.', $count, $email));
        return Command::SUCCESS;
    }
}
