<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ApiTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function generate(User $user): string
    {
        $token = bin2hex(random_bytes(32));

        $user->setApiToken($token);
        $user->setApiTokenGeneratedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $token;
    }

    public function revoke(User $user): void
    {
        $user->setApiToken(null);
        $user->setApiTokenGeneratedAt(null);

        $this->em->flush();
    }
}
