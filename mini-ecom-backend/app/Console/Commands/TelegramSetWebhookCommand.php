<?php

namespace App\Console\Commands;

use App\Support\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Registers the Telegram webhook URL with `secret_token` set to
 * `services.telegram.webhook_secret`, so every subsequent update Telegram sends carries the
 * `X-Telegram-Bot-Api-Secret-Token` header that `TelegramWebhookController` checks.
 *
 * Run once per environment, e.g.:
 *   php artisan telegram:set-webhook https://api.grocerly.example/v1/telegram/webhook
 */
#[Signature('telegram:set-webhook {url : The publicly reachable URL for POST /v1/telegram/webhook}')]
#[Description('Register this application\'s Telegram webhook URL with Telegram.')]
class TelegramSetWebhookCommand extends Command
{
    public function handle(TelegramClient $telegramClient): int
    {
        $url = $this->argument('url');

        if (blank(config('services.telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        if (blank(config('services.telegram.webhook_secret'))) {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not configured.');

            return self::FAILURE;
        }

        if (! $telegramClient->setWebhook($url)) {
            $this->error('Telegram rejected the webhook registration. Check the log for details.');

            return self::FAILURE;
        }

        $this->info("Telegram webhook registered: {$url}");

        return self::SUCCESS;
    }
}
