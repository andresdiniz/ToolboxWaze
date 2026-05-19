<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cria ou atualiza um usuário administrador padrão.
 *
 * Uso básico (usa valores padrão / interativo):
 *   php bin/console app:create-admin
 *
 * Com opções explícitas (ideal para CI/Docker):
 *   php bin/console app:create-admin \
 *       --email=admin@toolboxwaze.com \
 *       --password=SenhaSeg@123 \
 *       --name="Admin Principal" \
 *       --waze-nickname=AdminWaze \
 *       --no-interaction
 *
 * Se o e-mail já existir, atualiza a senha e promove a ROLE_ADMIN.
 */
#[AsCommand(
    name: 'app:create-admin',
    description: 'Cria ou atualiza um usuário administrador no ToolboxWaze',
)]
final class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email',         null, InputOption::VALUE_REQUIRED, 'E-mail do administrador')
            ->addOption('password',      null, InputOption::VALUE_REQUIRED, 'Senha (min. 8 caracteres)')
            ->addOption('name',          null, InputOption::VALUE_REQUIRED, 'Nome completo')
            ->addOption('waze-nickname', null, InputOption::VALUE_REQUIRED, 'Nickname no Waze');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ToolboxWaze — Criar Administrador');

        // --- Coletar dados (opções CLI ou prompt interativo) ---
        $email = $input->getOption('email')
            ?? $io->ask('E-mail do administrador', 'admin@wazetoolbox.com');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("E-mail inválido: {$email}");
            return Command::FAILURE;
        }

        $password = $input->getOption('password')
            ?? $io->askHidden('Senha (min. 8 caracteres) [deixe vazio para gerar automaticamente]');

        $generated = false;
        if (empty($password)) {
            $password  = $this->generatePassword();
            $generated = true;
        }

        if (strlen($password) < 8) {
            $io->error('A senha deve ter ao menos 8 caracteres.');
            return Command::FAILURE;
        }

        $name = $input->getOption('name')
            ?? $io->ask('Nome completo', 'Administrador');

        $wazeNickname = $input->getOption('waze-nickname')
            ?? $io->ask('Nickname no Waze', 'AdminWaze');

        // --- Buscar ou criar o usuário ---
        $user   = $this->userRepo->findOneBy(['email' => $email]);
        $isNew  = ($user === null);

        if ($isNew) {
            $user = new User();
            $user->setEmail($email);
        }

        $user->setName($name)
             ->setWazeNickname($wazeNickname)
             ->setPassword($this->hasher->hashPassword($user, $password))
             ->setStatus(User::STATUS_APPROVED)
             ->setApprovedAt(new \DateTimeImmutable())
             ->setRoles(['ROLE_ADMIN'])
             ->setPermissions(array_keys(User::ALL_PERMISSIONS));

        if ($isNew) {
            $this->em->persist($user);
        }
        $this->em->flush();

        // --- Resultado ---
        $io->newLine();
        $io->table(
            ['Campo', 'Valor'],
            [
                ['Acão',          $isNew ? '✅ Usuário criado' : '🔄 Usuário atualizado'],
                ['Nome',          $name],
                ['E-mail',        $email],
                ['Waze Nickname', $wazeNickname],
                ['Roles',         implode(', ', $user->getRoles())],
                ['Permissões',    implode(', ', $user->getPermissions())],
                ['Senha',         $generated ? $password . ' (GERADA AUTOMATICAMENTE — salve agora!)' : '(conforme informado)'],
            ]
        );

        if ($generated) {
            $io->warning('Senha gerada automaticamente acima. Salve-a agora, não será exibida novamente.');
        }

        $io->success('Administrador pronto! Acesse /login para entrar.');
        return Command::SUCCESS;
    }

    private function generatePassword(int $length = 16): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
        $pwd   = '';
        $max   = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $pwd .= $chars[random_int(0, $max)];
        }
        return $pwd;
    }
}
