<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create-super-admin',
    description: 'Creates a user with the Administrator role (ROLE_SUPER_ADMIN)',
)]
class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Administrator email')
            ->addArgument('password', InputArgument::REQUIRED, 'Password')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'User display name', 'Administrator')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Update existing user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');
        $name = trim((string) $input->getOption('name'));
        $force = (bool) $input->getOption('force');

        $existing = $this->userRepository->findOneBy(['email' => $email]);

        if ($existing && !$force) {
            $io->error(sprintf(
                'User with email "%s" already exists. Use --force to update.',
                $email,
            ));

            return Command::FAILURE;
        }

        $user = $existing ?? new User();
        $user
            ->setEmail($email)
            ->setName($name !== '' ? $name : 'Administrator')
            ->setRoles([UserRole::SuperAdmin->value])
            ->setIsActive(true);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $errors = $this->validator->validate($user);
        if (\count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error(sprintf('%s: %s', $error->getPropertyPath(), $error->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s Administrator: %s (id=%d)',
            $existing ? 'Updated' : 'Created',
            $user->getEmail(),
            $user->getId(),
        ));

        return Command::SUCCESS;
    }
}
