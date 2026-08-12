<?php

namespace App\Support\Bakong;

use App\Exceptions\ProblemException;
use App\Models\PaymentAttempt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class BakongGateway
{
    /**
     * Returns the provider response only when Bakong confirms the MD5-bound dynamic QR payment.
     * A client can request this check repeatedly, but can never supply a success state or an
     * alternate provider reference.
     *
     * @return array<string, mixed>|null null means Bakong reports no completed payment yet
     */
    public function verify(PaymentAttempt $attempt): ?array
    {
        if (! config('bakong.enabled') || blank(config('bakong.api_token'))) {
            throw ProblemException::paymentUnavailable();
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken((string) config('bakong.api_token'))
                ->connectTimeout((int) config('bakong.connect_timeout_seconds'))
                ->timeout((int) config('bakong.timeout_seconds'))
                ->post(
                    rtrim((string) config('bakong.base_url'), '/').'/'.ltrim((string) config('bakong.check_transaction_path'), '/'),
                    ['md5' => $attempt->provider_reference],
                );
        } catch (ConnectionException) {
            throw ProblemException::paymentVerificationFailed();
        }

        if (! $response->successful()) {
            throw ProblemException::paymentVerificationFailed();
        }

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('responseCode', $body)) {
            throw ProblemException::paymentVerificationFailed();
        }

        // Bakong's lookup is tied to the MD5 of the full QR payload. A successful lookup for
        // this server-generated MD5 confirms the destination account and amount encoded in it.
        if ((int) $body['responseCode'] !== 0 || empty($body['data'])) {
            return null;
        }

        return $body;
    }

    public function transactionHash(array $response): ?string
    {
        $data = $response['data'] ?? [];

        if (! is_array($data)) {
            return null;
        }

        foreach (['hash', 'transactionHash', 'transaction_hash'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value)) {
                return strtolower($value);
            }
        }

        return null;
    }
}
