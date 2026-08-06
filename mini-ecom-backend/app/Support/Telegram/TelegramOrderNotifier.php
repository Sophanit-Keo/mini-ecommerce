<?php

namespace App\Support\Telegram;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds and sends the order-related Telegram messages admins act on.
 *
 * Callback data is a short `order:{publicId}:{verb}` string — comfortably under Telegram's
 * 64-byte `callback_data` limit (a UUID public id is 36 chars, plus a short verb) — and is
 * parsed back into an action by `TelegramWebhookController`.
 */
class TelegramOrderNotifier
{
    public function __construct(private readonly TelegramClient $client) {}

    /**
     * Notify every admin with a linked Telegram chat that a new order needs confirmation.
     */
    public function notifyNewOrder(Order $order): void
    {
        $text = "🛒 <b>New order {$order->order_number}</b>\n"
            ."Items: {$order->items->count()}\n"
            ."Total: {$order->currency} {$order->total_estimated}\n"
            .'Delivery slot: '.($order->deliverySlot?->starts_at?->toDayDateTimeString() ?? 'n/a');

        $buttons = [[
            ['text' => '✅ Confirm', 'callback_data' => $this->callbackData($order, 'confirm')],
            ['text' => '❌ Reject', 'callback_data' => $this->callbackData($order, 'reject')],
        ]];

        foreach ($this->linkedAdmins() as $admin) {
            $this->client->sendMessage($admin->telegram_chat_id, $text, $buttons);
        }
    }

    /**
     * Confirm to the acting admin (via a follow-up message) that a transition landed.
     */
    public function notifyStatusChanged(Order $order, User $admin): void
    {
        if (blank($admin->telegram_chat_id)) {
            return;
        }

        $this->client->sendMessage(
            $admin->telegram_chat_id,
            "Order {$order->order_number} is now <b>{$order->status->value}</b>.",
        );
    }

    public function callbackData(Order $order, string $verb): string
    {
        return "order:{$order->public_id}:{$verb}";
    }

    /**
     * @return Collection<int, User>
     */
    private function linkedAdmins(): Collection
    {
        return User::where('role', UserRole::Admin)->whereNotNull('telegram_chat_id')->get();
    }
}
