<?php

namespace App\Support\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thin wrapper over the Telegram Bot HTTP API.
 *
 * This is a notification side-channel, not a critical path: every call swallows its own
 * failures (logged, not thrown) so a Telegram outage can never break order placement or an
 * admin's fulfilment action. Callers that need to know whether the message actually landed
 * should not rely on this class — none of the flows in this application do.
 */
class TelegramClient
{
    private const API_BASE = 'https://api.telegram.org';

    /**
     * Send a text message, optionally with an inline keyboard.
     *
     * @param  array<int, array<int, array{text: string, callback_data: string}>>|null  $inlineKeyboard  rows of buttons
     */
    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        $this->call('sendMessage', $payload);
    }

    /**
     * Acknowledge a callback query so Telegram stops showing the button's loading spinner.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], fn ($value) => $value !== null));
    }

    /**
     * Register the webhook URL with Telegram, protected by the configured secret token.
     */
    public function setWebhook(string $url): bool
    {
        $response = $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
        ]);

        return $response !== null && (bool) ($response['ok'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function call(string $method, array $payload): ?array
    {
        $token = config('services.telegram.bot_token');

        if (blank($token)) {
            Log::warning('Telegram bot token is not configured; skipping API call.', ['method' => $method]);

            return null;
        }

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->post(self::API_BASE."/bot{$token}/{$method}", $payload);

            if ($response->failed()) {
                Log::warning('Telegram API call failed.', [
                    'method' => $method,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::warning('Telegram API call threw an exception.', [
                'method' => $method,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
