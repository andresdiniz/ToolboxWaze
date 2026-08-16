<?php

declare(strict_types=1);

namespace App\Email\MessageHandler;

use App\Email\Message\SendEmailMessage;
use App\Email\Service\EmailNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendEmailMessageHandler
{
    public function __construct(private EmailNotificationService $emailService) {}

    public function __invoke(SendEmailMessage $message): void
    {
        $this->emailService->sendTemplate($message->to, $message->subject, $message->template, $message->context, $message->name, $message->text);
    }
}
