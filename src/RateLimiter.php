<?php

declare(strict_types=1);

namespace Weixun;

/**
 * 令牌桶限速器。
 *
 * 为什么 SDK 必须自带限速：协议端实测没有任何服务端限流，
 * 客户并发猛发不会收到 API 错误，而是直接被微信风控。
 */
final class RateLimiter
{
    private float $tokens;
    private float $last;
    private readonly float $intervalMs;

    public function __construct(
        private readonly int $capacity,
        int $perWindowMs
    ) {
        if ($capacity <= 0) {
            throw new \InvalidArgumentException('capacity 必须大于 0');
        }
        $this->intervalMs = $perWindowMs / $capacity;
        $this->tokens = (float) $capacity;
        $this->last = microtime(true) * 1000;
    }

    /** 阻塞直到获取一个令牌。 */
    public function acquire(): void
    {
        while (true) {
            $now = microtime(true) * 1000;
            $this->tokens = min($this->capacity, $this->tokens + ($now - $this->last) / $this->intervalMs);
            $this->last = $now;
            if ($this->tokens >= 1) {
                $this->tokens -= 1;
                return;
            }
            $need = (1 - $this->tokens) * $this->intervalMs;
            usleep((int) max($need * 1000, 1000));
        }
    }
}
