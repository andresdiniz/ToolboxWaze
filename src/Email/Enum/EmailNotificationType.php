<?php

declare(strict_types=1);

namespace App\Email\Enum;

enum EmailNotificationType: string
{
    case WELCOME_USER = 'welcome_user';
    case WELCOME_ADMIN = 'welcome_admin';
    case PASSWORD_RESET = 'password_reset';
    case PASSWORD_RESET_SUCCESS = 'password_reset_success';
    case RADAR_WEEKLY_DIGEST = 'radar_weekly_digest';
    case RADAR_EXPIRED = 'radar_expired';
}
