<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Crée un compte administrateur (ROLE_ADMIN)',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email de l\'administrateur')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user) {
            // L'utilisateur existe déjà → on lui ajoute ROLE_ADMIN s'il ne l'a pas
            $roles = $user->getRoles();
            if (!in_array('ROLE_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_ADMIN';
                // Si l'entité utilise setRoles()
                if (method_exists($user, 'setRoles')) {
                    $user->setRoles($roles);
                } elseif (method_exists($user, 'setRole')) {
                    $user->setRole('ROLE_ADMIN');
                }
                $this->em->flush();
                $io->success("ROLE_ADMIN ajouté à l'utilisateur $email.");
            } else {
                $io->info("L'utilisateur $email a déjà ROLE_ADMIN.");
            }
            return Command::SUCCESS;
        }

        // Création d'un nouvel administrateur
        $admin = new User();
        $admin->setEmail($email);

        if (method_exists($admin, 'setRoles')) {
            $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        } elseif (method_exists($admin, 'setRole')) {
            $admin->setRole('ROLE_ADMIN');
        }

        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));

        if (method_exists($admin, 'setIsVerified')) {
            $admin->setIsVerified(true);
        }
        if (method_exists($admin, 'setCreatedAt')) {
            $admin->setCreatedAt(new \DateTimeImmutable());
        }

        $this->em->persist($admin);
        $this->em->flush();

        $io->success("Administrateur $email créé avec succès !");
        return Command::SUCCESS;
    }
}