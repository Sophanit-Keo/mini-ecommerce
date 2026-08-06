<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkTelegramChatRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chatId' => ['required', 'string', 'max:32'],
        ];
    }
}
