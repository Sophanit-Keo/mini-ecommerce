<?php

namespace App\Support\Bakong;

use App\Exceptions\ProblemException;
use Illuminate\Support\Str;

/**
 * Builds a dynamic, amount-bound KHQR payload without accepting any merchant detail from a
 * browser. The QR's MD5 is later checked by the Bakong API, so the provider check is bound to
 * the server-generated amount, destination account, and bill reference.
 */
final class BakongKhqr
{
    /**
     * @return array{payload: string, reference: string}
     */
    public function create(string $amount, string $currency, string $reference): array
    {
        $this->assertConfigured();

        $currency = strtoupper($currency);
        $numericCurrency = match ($currency) {
            'USD' => '840',
            'KHR' => '116',
            default => throw ProblemException::paymentUnavailable(),
        };

        $amount = $this->formatAmount($amount, $currency);
        $account = $this->required('account_id');
        $name = $this->required('merchant_name');
        $city = $this->required('merchant_city');
        $profile = (string) config('bakong.profile_type');

        $accountTemplate = $this->tlv('00', $account);
        $accountTag = '29';

        if ($profile === 'merchant') {
            $accountTag = '30';
            $accountTemplate .= $this->tlv('01', $this->required('merchant_id'));
            $accountTemplate .= $this->tlv('02', $this->required('acquiring_bank'));
        } elseif ($profile !== 'individual') {
            throw ProblemException::paymentUnavailable();
        }

        // `reference` is unique per attempt and is embedded inside the MD5-bound QR. This makes
        // retrying an expired QR safe without accepting a transaction for a different order.
        $additionalData = $this->tlv('01', $reference);
        $mobile = (string) config('bakong.mobile_number');

        if ($mobile !== '') {
            $additionalData .= $this->tlv('02', $mobile);
        }

        $payloadWithoutCrc = implode('', [
            $this->tlv('00', '01'),
            $this->tlv('01', '12'), // dynamic QR: one payment attempt only
            $this->tlv($accountTag, $accountTemplate),
            $this->tlv('52', '5999'),
            $this->tlv('53', $numericCurrency),
            $this->tlv('54', $amount),
            $this->tlv('58', 'KH'),
            $this->tlv('59', $name),
            $this->tlv('60', $city),
            $this->tlv('62', $additionalData),
            '6304',
        ]);

        return [
            'payload' => $payloadWithoutCrc.$this->crc16($payloadWithoutCrc),
            'reference' => $reference,
        ];
    }

    public function uniqueReference(string $orderNumber): string
    {
        // KHQR bill-number fields are capped at 25 characters. Keep the order number visible to
        // operations while adding randomness so an expired retry cannot share a payment MD5.
        return Str::limit($orderNumber.'-'.Str::lower(Str::random(8)), 25, '');
    }

    public function assertConfigured(): void
    {
        if (! config('bakong.enabled')
            || blank(config('bakong.api_token'))
            || blank(config('bakong.account_id'))
            || blank(config('bakong.merchant_name'))
            || blank(config('bakong.merchant_city'))) {
            throw ProblemException::paymentUnavailable();
        }

        foreach (['account_id', 'merchant_name', 'merchant_city', 'merchant_id', 'acquiring_bank', 'mobile_number'] as $key) {
            $value = (string) config("bakong.{$key}");

            if ($value !== '' && ! preg_match('/^[\x20-\x7E]+$/', $value)) {
                // Primary EMV fields must be byte-counted. Until a merchant provides a verified
                // localized KHQR profile, fail closed rather than emitting a malformed QR.
                throw ProblemException::paymentUnavailable();
            }
        }

        if ((string) config('bakong.profile_type') === 'merchant'
            && (blank(config('bakong.merchant_id')) || blank(config('bakong.acquiring_bank')))) {
            throw ProblemException::paymentUnavailable();
        }
    }

    private function required(string $key): string
    {
        $value = (string) config("bakong.{$key}");

        if ($value === '') {
            throw ProblemException::paymentUnavailable();
        }

        return $value;
    }

    private function formatAmount(string $amount, string $currency): string
    {
        if ($currency === 'KHR') {
            if (! preg_match('/^\d+\.?(?:0+)?$/', $amount)) {
                throw ProblemException::paymentUnavailable();
            }

            return (string) (int) $amount;
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw ProblemException::paymentUnavailable();
        }

        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }

    private function tlv(string $tag, string $value): string
    {
        $length = strlen($value);

        if ($length > 99) {
            throw ProblemException::paymentUnavailable();
        }

        return $tag.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;

        foreach (str_split($payload) as $character) {
            $crc ^= ord($character) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
