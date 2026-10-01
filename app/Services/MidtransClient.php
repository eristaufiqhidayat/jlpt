<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien Midtrans Snap tanpa SDK (cukup HTTP client Laravel).
 *
 * - createTransaction(): minta Snap token untuk ditampilkan dengan snap.js
 * - status(): cek status transaksi langsung ke Midtrans
 * - isValidSignature(): verifikasi notifikasi HTTP dari Midtrans
 *   signature_key = SHA512(order_id + status_code + gross_amount + server_key)
 */
class MidtransClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.midtrans.server_key')) && filled(config('services.midtrans.client_key'));
    }

    public function isProduction(): bool
    {
        return (bool) config('services.midtrans.is_production');
    }

    public function clientKey(): ?string
    {
        return config('services.midtrans.client_key');
    }

    public function snapJsUrl(): string
    {
        return config('services.midtrans.snap_js_url')
            ?: ($this->isProduction() ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js');
    }

    private function snapUrl(): string
    {
        return rtrim(config('services.midtrans.snap_url')
            ?: ($this->isProduction() ? 'https://app.midtrans.com/snap/v1' : 'https://app.sandbox.midtrans.com/snap/v1'), '/');
    }

    private function apiUrl(): string
    {
        return rtrim(config('services.midtrans.api_url')
            ?: ($this->isProduction() ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2'), '/');
    }

    private function http()
    {
        return Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()->asJson()->timeout(20);
    }

    /**
     * @return array{token: string, redirect_url: string}
     */
    public function createTransaction(array $payload): array
    {
        $this->ensureConfigured();

        try {
            $response = $this->http()->post($this->snapUrl().'/transactions', $payload)->throw()->json();
        } catch (RequestException $e) {
            $messages = implode('; ', (array) ($e->response?->json('error_messages') ?? [$e->getMessage()]));
            throw new RuntimeException('Midtrans menolak transaksi: '.$messages, previous: $e);
        }

        if (empty($response['token'])) {
            throw new RuntimeException('Midtrans tidak mengembalikan token pembayaran.');
        }

        return ['token' => $response['token'], 'redirect_url' => $response['redirect_url'] ?? ''];
    }

    /** Status terbaru dari Midtrans (null bila transaksi belum dibuat/ tidak ditemukan). */
    public function status(string $orderId): ?array
    {
        $this->ensureConfigured();
        $response = $this->http()->get($this->apiUrl().'/'.rawurlencode($orderId).'/status');

        if ($response->failed()) {
            return null;
        }
        $data = $response->json();

        // Midtrans mengembalikan HTTP 200 dengan status_code 404 bila order belum ada.
        return isset($data['transaction_status']) ? $data : null;
    }

    public function isValidSignature(array $n): bool
    {
        $key = (string) config('services.midtrans.server_key');
        if ($key === '' || empty($n['signature_key']) || ! isset($n['order_id'], $n['status_code'], $n['gross_amount'])) {
            return false;
        }

        $expected = hash('sha512', $n['order_id'].$n['status_code'].$n['gross_amount'].$key);

        return hash_equals($expected, (string) $n['signature_key']);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Midtrans belum dikonfigurasi (MIDTRANS_SERVER_KEY / MIDTRANS_CLIENT_KEY).');
        }
    }
}
