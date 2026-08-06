<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkTelegramChatRequest;
use Illuminate\Http\JsonResponse;

/**
 * Lets an admin register the Telegram chat that should receive order notifications and be
 * allowed to act on them via inline buttons.
 *
 * The admin gets their own chat id from Telegram (e.g. `@userinfobot`, or by messaging the
 * bot first) and pastes it in here — the link itself is not verified against a real chat,
 * which keeps this simple; an admin who mistypes their chat id simply never receives
 * messages.
 */
class AdminTelegramController extends Controller
{
    public function link(LinkTelegramChatRequest $request): JsonResponse
    {
        $request->user()->update([
            'telegram_chat_id' => $request->string('chatId')->toString(),
        ]);

        return response()->json([
            'telegramChatId' => $request->user()->telegram_chat_id,
        ]);
    }
}
