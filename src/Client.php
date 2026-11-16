<?php

declare(strict_types=1);

namespace Weixun;

/**
 * 微寻微信协议 API 的 PHP 客户端。
 *
 * ```php
 * $c = new Client('http://127.0.0.1:7006', '你的协议 license Key');
 * $res = $c->message()->sendText('wxid_xxx', '订单已发货');
 * if (!$res->ok) { echo $res->errorMessage; }
 * ```
 *
 * 设计要点（均来自协议端实测）：
 *  - license key 一律走 query 参数 key
 *  - HTTP 状态码几乎恒为 200，成败看响应体 Code
 *  - 64 位 ID 用字符串（PHP int 在 64 位平台够用，但保持跨语言一致）
 *  - 协议端完全不限流，SDK 默认 40 条/分钟
 */
final class Client
{
    private readonly MessageService $message;
    private readonly LoginService $login;
    private readonly UploadService $upload;
    private ?RateLimiter $limiter;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $licenseKey,
        int $rateLimitPerMinute = 40,
        private readonly int $timeoutSeconds = 30
    ) {
        $this->limiter = $rateLimitPerMinute > 0
            ? new RateLimiter($rateLimitPerMinute, 60000)
            : null;
        $this->message = new MessageService($this);
        $this->login = new LoginService($this);
        $this->upload = new UploadService($this);
    }

    public function message(): MessageService
    {
        return $this->message;
    }

    public function login(): LoginService
    {
        return $this->login;
    }

    public function upload(): UploadService
    {
        return $this->upload;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** 供 UploadService 构造 multipart 请求时取原始 key。 */
    public function rawKey(): string
    {
        return $this->licenseKey;
    }

    /** 脱敏后的 key，用于日志。 */
    public function keyMasked(): string
    {
        $k = $this->licenseKey;
        if (strlen($k) <= 8) {
            return '****';
        }
        return substr($k, 0, 4) . '****' . substr($k, -4);
    }

    /**
     * 内部：统一调用出口。
     *
     * @return mixed Data 字段
     */
    public function call(string $method, string $path, ?array $body = null): mixed
    {
        $this->limiter?->acquire();

        $url = rtrim($this->baseUrl, '/') . $path
            . (str_contains($path, '?') ? '&' : '?')
            . 'key=' . rawurlencode($this->licenseKey);

        $headers = [
            'Accept: application/json',
            'User-Agent: weixun-php/0.1.0',
        ];
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true, // 便于读取 4xx/5xx 的响应体
            ],
        ];
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            $opts['http']['header'] .= "\r\nContent-Type: application/json";
            $opts['http']['content'] = $json;
        }

        $raw = @file_get_contents($url, false, stream_context_create($opts));
        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        if ($raw === false || $raw === '') {
            throw new APIError(Envelope::CODE_FAIL_GENERIC, '请求失败或响应为空', '', $status);
        }

        $text = ltrim($raw);
        if ($text === '' || $text[0] !== '{') {
            throw new APIError(Envelope::CODE_FAIL_GENERIC, trim($raw), '', $status, $raw);
        }

        $env = json_decode($raw, true);
        if (!is_array($env)) {
            throw new APIError(Envelope::CODE_FAIL_GENERIC, '响应不是合法 JSON', '', $status, $raw);
        }
        if (!Envelope::isOk($env)) {
            throw Envelope::error($env, $status, $raw);
        }
        return $env['Data'] ?? null;
    }
}
