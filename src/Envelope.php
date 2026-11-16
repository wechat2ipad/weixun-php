<?php

declare(strict_types=1);

namespace Weixun;

/**
 * 统一响应信封与错误类型。
 *
 * 判定语义（协议端实测）：
 *   1. Code 存在且为 0 或 200 → 成功
 *   2. Code 不存在 → 回退 Success === true
 *   3. 都无法判定 → 失败
 *
 * 关键反例：/user/SetProxy 返回 Code=200 但 Success=false，绝不能单看 Success。
 */
final class Envelope
{
    // 协议端业务码（取自 api/vo/vo.go）
    public const CODE_SUCCESS = 200;
    public const CODE_LEGACY_OK = 0;
    public const CODE_FAIL_DATA = -1;
    public const CODE_FAIL_KEY = -2;
    public const CODE_FAIL_BOUND = -3;
    public const CODE_FAIL_OFFLINE = -4;
    public const CODE_FAIL_NOT_LOCAL = -5;
    public const CODE_FAIL_GENERIC = 300;

    // 登录/代理相关原因码
    public const REASON_LOGIN_SESSION_NOT_FOUND = 'LOGIN_SESSION_NOT_FOUND';
    public const REASON_PROXY_OFFLINE = 'PROXY_OFFLINE';
    public const REASON_ATTEMPT_CONFLICT = 'ATTEMPT_CONFLICT';
    public const REASON_ATTEMPT_MISMATCH = 'ATTEMPT_MISMATCH';
    public const REASON_ATTEMPT_INACTIVE = 'ATTEMPT_INACTIVE';
    public const REASON_ATTEMPT_ID_REQUIRED = 'ATTEMPT_ID_REQUIRED';
    public const REASON_ATTEMPT_NOT_FOUND = 'ATTEMPT_NOT_FOUND';
    public const REASON_ATTEMPT_EXPIRED = 'ATTEMPT_EXPIRED';
    public const REASON_CANCELLED = 'CANCELLED';
    public const REASON_WAKE_SCAN_REQUIRED = 'WAKE_SCAN_REQUIRED';
    public const REASON_SECURITY_RELOGIN_REQUIRED = 'SECURITY_RELOGIN_REQUIRED';
    public const REASON_SESSION_INVALID = 'SESSION_INVALID';
    public const REASON_WAKE_REQUEST_FAILED = 'WAKE_REQUEST_FAILED';
    public const REASON_LOGIN_REQUEST_FAILED = 'LOGIN_REQUEST_FAILED';

    /**
     * 把 Code 解析成整数。协议端的 Code 可能是 200（数字）或 "200"（字符串）。
     */
    public static function parseCode(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            return $raw == (int) $raw ? (int) $raw : null;
        }
        if (is_string($raw)) {
            $s = trim($raw);
            if ($s === '' || !preg_match('/^-?\d+$/', $s)) {
                return null;
            }
            return (int) $s;
        }
        return null;
    }

    /** 按协议端真实语义判定成功。 */
    public static function isOk(?array $env): bool
    {
        if ($env === null) {
            return false;
        }
        $code = self::parseCode($env['Code'] ?? null);
        if ($code !== null) {
            return $code === self::CODE_LEGACY_OK || $code === self::CODE_SUCCESS;
        }
        return ($env['Success'] ?? null) === true;
    }

    /** 可展示的失败说明。优先 Text，回退 Message（VerifyCode 用 Message）。 */
    public static function message(?array $env): string
    {
        if ($env === null) {
            return '';
        }
        $text = trim((string) ($env['Text'] ?? ''));
        if ($text !== '') {
            return $text;
        }
        return (string) ($env['Message'] ?? '');
    }

    /** 由响应信封构造异常。 */
    public static function error(?array $env, int $statusCode, string $body): APIError
    {
        $code = self::parseCode($env['Code'] ?? null) ?? self::CODE_FAIL_GENERIC;
        $text = self::message($env);
        if ($text === '' && $statusCode !== 200) {
            $text = "HTTP {$statusCode}";
        }
        return new APIError(
            $code,
            $text,
            (string) ($env['reasonCode'] ?? ''),
            $statusCode,
            $body
        );
    }
}

/**
 * 所有业务失败的统一异常类型。
 */
class APIError extends \RuntimeException
{
    // 注意：Exception 已内置 $code 属性，不能重新声明为 readonly，
    // 这里改用 bizCode 保存协议端业务码，并通过 code() 暴露。
    public readonly string $text;
    public readonly string $reasonCode;
    public readonly int $statusCode;
    public readonly string $body;

    public function __construct(
        int $bizCode,
        string $text,
        string $reasonCode = '',
        int $statusCode = 200,
        string $body = ''
    ) {
        $label = $reasonCode === ''
            ? "weixun: code={$bizCode} msg={$text}"
            : "weixun: code={$bizCode} reason={$reasonCode} msg={$text}";
        parent::__construct($label, $bizCode);
        $this->text = $text;
        $this->reasonCode = $reasonCode;
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    /** 协议端业务码（Exception::$code 已被占用，用本方法取）。 */
    public function code(): int
    {
        return (int) $this->getCode();
    }

    /** 是否为 key 无效类错误，调用方据此提示重新登录。 */
    public function isKeyInvalid(): bool
    {
        return $this->code() === Envelope::CODE_FAIL_KEY
            || $this->reasonCode === Envelope::REASON_LOGIN_SESSION_NOT_FOUND;
    }

    /** 是否疑似被风控限流。 */
    public function isRateLimited(): bool
    {
        if ($this->statusCode === 429) {
            return true;
        }
        foreach (['频繁', '限制', 'frequency'] as $kw) {
            if (str_contains($this->text, $kw)) {
                return true;
            }
        }
        return false;
    }
}
