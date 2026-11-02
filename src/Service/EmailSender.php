<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Serviço central de envio de e-mails.
 *
 * Garante que TODOS os e-mails do sistema usem o mesmo remetente
 * ("ToolboxWaze <MAILER_FROM_EMAIL>") e a mesma URL base.
 */
final class EmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(string:default::MAILER_FROM_EMAIL)%')]
        private readonly string $fromEmail = '',
        #[Autowire('%env(string:default::APP_BASE_URL)%')]
        private readonly string $baseUrl = '',
    ) {}

    /**
     * Remetente padronizado de todos os e-mails.
     */
    public function from(): Address
    {
        $email = trim($this->fromEmail);

        return new Address(
            $email !== '' ? $email : 'noreply@toolboxwaze.com.br',
            'ToolboxWaze'
        );
    }

    /**
     * URL base do sistema, sem barra final.
     */
    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Envia um e-mail HTML já renderizado.
     */
    public function send(string $to, ?string $toName, string $subject, string $html): void
    {
        $this->mailer->send(
            (new Email())
                ->from($this->from())
                ->to(new Address($to, $toName ?? $to))
                ->subject($subject)
                ->html($html)
        );
    }

    /**
     * Envia um e-mail usando um template Twig.
     */
    public function sendTemplated(
        string $to,
        ?string $toName,
        string $subject,
        string $template,
        array $context = [],
    ): void {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->from())
                ->to(new Address($to, $toName ?? $to))
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context)
        );
    }
}
