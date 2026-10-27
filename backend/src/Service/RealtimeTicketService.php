<?php

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;

class RealtimeTicketService
{
    private const DEFAULT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    /**
     * @param list<string> $topics
     *
     * @return array{token: string, hubUrl: string, expiresAt: int, topics: list<string>}
     */
    public function issue(array $topics, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $normalized = [];
        foreach ($topics as $topic) {
            if (!\is_string($topic) || '' === $topic) {
                continue;
            }
            $normalized[$topic] = $topic;
        }
        $topics = array_values($normalized);

        if ([] === $topics) {
            $topics = ['admin.routes'];
        }

        $factory = $this->hub->getFactory();
        if (null === $factory) {
            throw new \RuntimeException('Mercure hub has no JWT token factory configured.');
        }

        $ttlSeconds = max(60, $ttlSeconds);
        $expiresAt = time() + $ttlSeconds;

        $token = $factory->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], $topics)],
            ['exp' => (new \DateTimeImmutable())->setTimestamp($expiresAt)],
        );

        return [
            'token' => $token,
            'hubUrl' => $this->hub->getPublicUrl(),
            'expiresAt' => $expiresAt,
            'topics' => $topics,
        ];
    }
}
