<?php

declare(strict_types=1);

namespace Weixun;

/**
 * 扫码登录状态机。
 *
 * 状态机（协议端实测 loginState 取值）：
 *  - waiting 等待扫码
 *  - login_processing 已扫码，处理中
 *  - ipad_required 需要 iPad 二次确认（双扫）
 *  - online 登录完成
 *  - security_verify_required 需要安全验证，必须重新登录
 */
final class LoginService
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * 获取登录二维码。
     *
     * 协议端要求同一 key 同时只能有一个活跃的登录尝试（15 分钟 TTL）。
     */
    public function getQrCode(string $device = 'ipad'): LoginState
    {
        $data = $this->client->call('POST', '/login/GetLoginQrCodeNewDirect', [
            'device' => $device !== '' ? $device : 'ipad',
        ]);
        return LoginState::parse($data);
    }

    /** 查询登录进度，建议每 2.5 秒一次。 */
    public function checkStatus(string $attemptId = ''): LoginState
    {
        $body = [];
        if ($attemptId !== '') {
            $body['attemptId'] = $attemptId;
        }
        $st = LoginState::parse($this->client->call('POST', '/login/CheckLoginStatus', $body));
        if ($st->attemptId === '') {
            $st->attemptId = $attemptId;
        }
        return $st;
    }

    /** 阻塞等待登录完成。 */
    public function waitForOnline(string $attemptId = '', float $intervalSec = 2.5, float $timeoutSec = 300.0): LoginState
    {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $st = $this->checkStatus($attemptId);
            if ($st->isOnline()) {
                return $st;
            }
            if ($st->needSecurityVerify()) {
                throw new APIError(
                    Envelope::CODE_FAIL_GENERIC,
                    '需要安全验证，必须重新发起登录',
                    Envelope::REASON_SECURITY_RELOGIN_REQUIRED
                );
            }
            usleep((int) ($intervalSec * 1_000_000));
        }
        throw new APIError(Envelope::CODE_FAIL_GENERIC, '等待登录超时');
    }

    /** 取消当前登录尝试。 */
    public function cancel(string $attemptId = ''): void
    {
        $body = [];
        if ($attemptId !== '') {
            $body['attemptId'] = $attemptId;
        }
        $this->client->call('POST', '/login/CancelLogin', $body);
    }

    /** 查询账号当前是否在线。 */
    public function getLoginStatus(): mixed
    {
        return $this->client->call('GET', '/login/GetLoginStatus');
    }

    /** 唤醒已登录过的账号（避免重新扫码）。 */
    public function wakeUp(string $attemptId = ''): LoginState
    {
        $body = [];
        if ($attemptId !== '') {
            $body['attemptId'] = $attemptId;
        }
        return LoginState::parse($this->client->call('POST', '/login/WakeUpLogin', $body));
    }
}

/** 扫码登录的当前状态。 */
final class LoginState
{
    public int $state = 0;
    public string $loginState = '';
    public string $nextStep = '';
    public string $qrCodeUrl = '';
    public string $wxid = '';
    public string $message = '';
    public string $attemptId = '';

    public static function parse(mixed $data): self
    {
        $d = is_array($data) ? $data : [];
        $s = new self();
        $s->state = (int) ($d['state'] ?? 0);
        $s->loginState = (string) ($d['loginState'] ?? '');
        $s->nextStep = (string) ($d['nextStep'] ?? '');
        $s->qrCodeUrl = (string) ($d['qrCodeUrl'] ?? $d['QrCodeUrl'] ?? '');
        $s->wxid = (string) ($d['wxid'] ?? '');
        $s->message = (string) ($d['msg'] ?? '');
        $s->attemptId = (string) ($d['attemptId'] ?? '');
        return $s;
    }

    public function isOnline(): bool
    {
        return $this->loginState === 'online';
    }

    public function needIpadConfirm(): bool
    {
        return $this->loginState === 'ipad_required';
    }

    public function needSecurityVerify(): bool
    {
        return $this->loginState === 'security_verify_required';
    }

    public function isTerminal(): bool
    {
        return $this->isOnline() || $this->needSecurityVerify();
    }
}
