<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\AdvanceOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TelegramLinkChallenge;
use App\Models\User;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramOrderNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Receives `callback_query` updates from Telegram's inline order-action buttons.
 *
 * Unauthenticated by necessity — Telegram cannot send a Bearer token — so the only proof
 * this request genuinely came from Telegram is the `X-Telegram-Bot-Api-Secret-Token`
 * header, set on our side via `setWebhook`'s `secret_token` param (see
 * `telegram:set-webhook`) and checked against `services.telegram.webhook_secret` here.
 *
 * The caller's identity is *not* the Sanctum user on the request (there isn't one) — it is
 * whichever admin has linked the chat id the callback's `from.id` matches. A callback from an
 * unlinked chat is silently ignored: Telegram's `from.id` equals the private chat id in a 1:1
 * bot conversation, so this is exactly the check that keeps a stranger who discovers the bot
 * from moving anyone's orders.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly AdvanceOrderStatus $advanceOrderStatus,
        private readonly TelegramClient $telegramClient,
        private readonly TelegramOrderNotifier $telegramOrderNotifier,
    ) {}

    public function handle(Request $request): Response
    {
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (blank($secret) || ! hash_equals((string) config('services.telegram.webhook_secret'), (string) $secret)) {
            throw ProblemException::forbidden();
        }

        $message = $request->input('message');
        if (is_array($message)) {
            $this->consumeLinkChallenge($message);

            return response()->noContent();
        }

        $callback = $request->input('callback_query');

        if (! is_array($callback)) {
            // Not a callback_query update (e.g. a plain message) — nothing for the bot to do.
            return response()->noContent();
        }

        $chatId = (string) ($callback['from']['id'] ?? '');
        $callbackQueryId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');

        $admin = User::where('role', UserRole::Admin)->where('telegram_chat_id', $chatId)->first();

        if ($admin === null) {
            $this->telegramClient->answerCallbackQuery($callbackQueryId, 'You are not linked to an admin account.');

            return response()->noContent();
        }

        $parsed = $this->parseCallbackData($data);

        if ($parsed === null) {
            $this->telegramClient->answerCallbackQuery($callbackQueryId, 'Unrecognised action.');

            return response()->noContent();
        }

        [$orderId, $action] = $parsed;

        $order = Order::wherePublicId($orderId)->first();

        if ($order === null) {
            $this->telegramClient->answerCallbackQuery($callbackQueryId, 'No such order.');

            return response()->noContent();
        }

        try {
            $order = $this->advanceOrderStatus->handle($order, $action, $admin);
        } catch (ProblemException $e) {
            $this->telegramClient->answerCallbackQuery($callbackQueryId, $e->title);

            return response()->noContent();
        }

        $this->telegramClient->answerCallbackQuery($callbackQueryId, 'Done.');
        $this->telegramOrderNotifier->notifyStatusChanged($order, $admin);

        return response()->noContent();
    }

    /** @param array<string, mixed> $message */
    private function consumeLinkChallenge(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($chatId === '' || ! preg_match('/^\\/link\\s+([A-Z0-9]{8})$/i', $text, $matches)) {
            return;
        }

        DB::transaction(function () use ($chatId, $matches): void {
            $challenge = TelegramLinkChallenge::query()->where('code_hash', hash('sha256', strtoupper($matches[1])))->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($challenge === null) {
                return;
            }
            $challenge->user()->lockForUpdate()->firstOrFail()->update(['telegram_chat_id' => $chatId]);
            $challenge->update(['consumed_at' => now()]);
        });
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseCallbackData(string $data): ?array
    {
        $parts = explode(':', $data);

        if (count($parts) !== 3 || $parts[0] !== 'order' || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        return [$parts[1], $parts[2]];
    }
}
