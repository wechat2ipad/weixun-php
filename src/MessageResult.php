<?php

declare(strict_types=1);

namespace Weixun;

/** 单条发送的结果（已归一化协议端的字段差异）。 */
final class MessageResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $to,
        public readonly string $errorMessage,
        public readonly array $raw
    ) {
    }

    public function __toString(): string
    {
        return $this->ok
            ? "OK(to={$this->to})"
            : "FAIL(to={$this->to}, error={$this->errorMessage})";
    }
}
