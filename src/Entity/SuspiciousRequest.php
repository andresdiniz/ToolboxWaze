<?php

namespace App\Entity;

use App\Repository\SuspiciousRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SuspiciousRequestRepository::class)]
#[ORM\Table(name: 'suspicious_requests')]
#[ORM\Index(columns: ['ip'], name: 'idx_suspicious_ip')]
#[ORM\Index(columns: ['created_at'], name: 'idx_suspicious_created_at')]
class SuspiciousRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 500)]
    private string $userAgent;

    #[ORM\Column(length: 500)]
    private string $path;

    #[ORM\Column(type: 'text')]
    private string $reasons;

    #[ORM\Column(length: 10)]
    private string $action;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): static
    {
        $this->ip = $ip;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;
        return $this;
    }

    public function getReasons(): string
    {
        return $this->reasons;
    }

    public function setReasons(string $reasons): static
    {
        $this->reasons = $reasons;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
