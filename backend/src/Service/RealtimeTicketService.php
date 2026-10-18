<?php

namespace App\Service;

class RealtimeTicketService
{
    private const DEFAULT_TTL_SECONDS = 60;

    public function __construct(
        private readonly string $secret,
        private readonly string $publicWsUrl,
    ) {
    }

    /**
     * @param list<string> $channels
     *
     * @return array{ticket: string, wsUrl: string, expiresAt: int, channels: list<string>}
     */
    public function issue(array $channels, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $normalized = [];
        foreach ($channels as $channel) {
            if (!\is_string($channel) || '' === $channel) {
                continue;
            }
            $normalized[$channel] = $channel;
        }
        $channels = array_values($normalized);

        if ([] === $channels) {
            $channels = ['admin.routes'];
        }

        $expiresAt = time() + max(15, $ttlSeconds);
        $payload = json_encode(
            [
                'exp' => $expiresAt,
                'channels' => $channels,
            ],
            \JSON_THROW_ON_ERROR,
        );

        $payloadB64 = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $payloadB64, $this->secret, true);
        $ticket = $payloadB64.'.'.$this->base64UrlEncode($signature);

        return [
            'ticket' => $ticket,
            'wsUrl' => $this->publicWsUrl,
            'expiresAt' => $expiresAt,
            'channels' => $channels,
        ];
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
