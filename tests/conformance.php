<?php

declare(strict_types=1);

/**
 * Conformance 测试：读取契约仓同一批 fixtures，验证 PHP SDK 行为与其他语言一致。
 *
 * 运行：php tests/conformance.php
 * 不依赖 PHPUnit，方便在没有 composer 的环境里也能跑。
 */

require_once __DIR__ . '/../src/Envelope.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/MessageResult.php';
require_once __DIR__ . '/../src/MessageService.php';
require_once __DIR__ . '/../src/LoginService.php';
require_once __DIR__ . '/../src/StreamFrame.php';
require_once __DIR__ . '/../src/UploadService.php';
require_once __DIR__ . '/../src/Client.php';

use Weixun\Client;
use Weixun\Envelope;
use Weixun\MessageService;

$failures = 0;
$passed = 0;

function check(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        $fn();
        $passed++;
        echo "  ✅ {$name}\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "  ❌ {$name}: " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new \RuntimeException($msg !== '' ? $msg : '断言失败：期望 true');
    }
}

function assertSame(mixed $want, mixed $got, string $msg = ''): void
{
    if ($want !== $got) {
        throw new \RuntimeException(
            ($msg !== '' ? $msg . ': ' : '') .
            '期望 ' . var_export($want, true) . '，实际 ' . var_export($got, true)
        );
    }
}

function fixturesDir(): string
{
    $candidates = [
        __DIR__ . '/../conformance/fixtures',
        __DIR__ . '/../../weixun-api-contract/fixtures',
    ];
    foreach ($candidates as $c) {
        if (is_dir($c)) {
            return $c;
        }
    }
    throw new \RuntimeException('找不到 fixtures 目录');
}

function load(string $rel): array
{
    $path = fixturesDir() . '/' . $rel;
    $body = file_get_contents($path);
    if ($body === false) {
        throw new \RuntimeException("读取 {$rel} 失败");
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

echo "PHP SDK Conformance\n";

// ── envelope ────────────────────────────────────────

check('envelope: 标准成功', function () {
    $fx = load('envelope/01-ok.json');
    assertSame($fx['expect']['ok'], Envelope::isOk($fx['input']));
    assertSame($fx['expect']['code'], Envelope::parseCode($fx['input']['Code']));
});

check('envelope: Code 是字符串 "200"', function () {
    $fx = load('envelope/02-code-string.json');
    assertTrue(Envelope::isOk($fx['input']));
    assertSame(200, Envelope::parseCode($fx['input']['Code']));
});

check('envelope: Code=200 但 Success=false（关键反例）', function () {
    $fx = load('envelope/03-success-false-code-200.json');
    assertSame($fx['expect']['ok'], Envelope::isOk($fx['input']));
    assertSame(200, Envelope::parseCode($fx['input']['Code']));
});

check('envelope: VerifyCode 特例（Code=0 + Message）', function () {
    $fx = load('envelope/04-verify-code.json');
    assertTrue(Envelope::isOk($fx['input']));
    assertSame(0, Envelope::parseCode($fx['input']['Code']));
    assertSame('验证成功', Envelope::message($fx['input']));
});

check('envelope: 负数码', function () {
    $fx = load('envelope/05-error-negative.json');
    assertTrue(!Envelope::isOk($fx['input']));
    assertSame(-2, Envelope::parseCode($fx['input']['Code']));
    assertSame(trim($fx['expect']['message_text']), Envelope::message($fx['input']));
});

check('envelope: reasonCode', function () {
    $fx = load('envelope/06-reason-code.json');
    assertTrue(!Envelope::isOk($fx['input']));
    assertSame('PROXY_OFFLINE', $fx['input']['reasonCode']);
});

// ── message ─────────────────────────────────────────

check('message: 批量部分成功（字段名不统一）', function () {
    $fx = load('message/01-partial-success.json');
    $results = MessageService::parseBatchResults($fx['input']);
    assertSame($fx['expect']['count'], count($results));
    foreach ($fx['expect']['items'] as $i => $want) {
        assertSame($want['to'], $results[$i]->to, "第 {$i} 条 to");
        assertSame($want['ok'], $results[$i]->ok, "第 {$i} 条 ok");
        assertSame($want['error'], $results[$i]->errorMessage, "第 {$i} 条 error");
    }
});

check('message: errMgs 拼写错误兼容', function () {
    $fx = load('message/02-errmgs-typo.json');
    $results = MessageService::parseBatchResults($fx['input']);
    assertSame(1, count($results));
    assertTrue(!$results[0]->ok);
    assertSame('上传失败', $results[0]->errorMessage);
});

check('message: 单对象返回（非数组）', function () {
    $fx = load('message/03-single-object.json');
    $results = MessageService::parseBatchResults($fx['input']);
    assertSame(1, count($results));
    assertSame('wxid_a', $results[0]->to);
    assertTrue($results[0]->ok);
});

// ── 大整数 ──────────────────────────────────────────

check('bigint: PHP 64 位 int 能精确表示', function () {
    $raw = '{"new_msg_id":199344895681685661}';
    $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_int($parsed['new_msg_id']), '应为 int 类型');
    assertSame('199344895681685661', (string) $parsed['new_msg_id']);
});

// ── 限速 ────────────────────────────────────────────

check('ratelimit: 前两次立即可用', function () {
    $lim = new \Weixun\RateLimiter(2, 100);
    $start = microtime(true) * 1000;
    $lim->acquire();
    $lim->acquire();
    $elapsed = microtime(true) * 1000 - $start;
    assertTrue($elapsed < 20, "前两次应立即可用，实际耗时 {$elapsed}ms");
});

// ── 元信息 ──────────────────────────────────────────

check('client: key 脱敏', function () {
    $c = new Client('http://127.0.0.1:7006', 'abcdefgh12345678');
    assertSame('abcd****5678', $c->keyMasked());
});

check('error: key 无效映射', function () {
    $err = Envelope::error(['Code' => -2, 'Text' => '该链接不存在', 'Success' => false], 200, '');
    assertSame(-2, $err->code());
    assertTrue($err->isKeyInvalid());
});


// ── ws 帧解析 ───────────────────────────────────────

check('ws: 普通消息帧（{str} 解包 + 64 位 ID）', function () {
    $raw = '{"key":"a1b2c3","message":{'
        . '"msg_id":1751755490,'
        . '"from_user_name":{"str":"50190590530@chatroom"},'
        . '"to_user_name":{"str":"wxid_hvoabx1dxaci22"},'
        . '"msg_type":1,'
        . '"content":{"str":"hello"},'
        . '"create_time":1789611963,'
        . '"new_msg_id":199344895681685661'
        . '},"type":"message"}';

    $fx = load('ws/01-message-frame.json');
    assertTrue(str_contains(json_encode($fx), '199344895681685661'), 'fixture 应用会丢精度的值');

    $frame = \Weixun\StreamFrame::parseFrame($raw);
    assertSame('message', $frame['kind']);
    $m = $frame['message'];
    assertSame('50190590530@chatroom', $m['from']);
    assertSame('wxid_hvoabx1dxaci22', $m['to']);
    assertSame(1, $m['type']);
    assertSame('199344895681685661', $m['new_msg_id'], '64 位 ID 丢精度');
    assertSame(1751755490, $m['msg_id']);
});

check('ws: record_content 事件', function () {
    $raw = '{"key":"k1","type":"record_content","message_id":"123456789",'
        . '"talker":"wxid_a","success":true,"content":"<msg/>","length":120,"timestamp":1789611963}';
    $frame = \Weixun\StreamFrame::parseFrame($raw);
    assertSame('record_content', $frame['kind']);
    assertSame('123456789', $frame['event']['message_id']);
    assertSame('wxid_a', $frame['event']['talker']);
    assertTrue($frame['event']['success']);
});

check('ws: 裸文本控制帧被忽略', function () {
    foreach (['pong', 'sync completed', 'ping'] as $t) {
        $frame = \Weixun\StreamFrame::parseFrame($t);
        assertSame('control', $frame['kind'], "{$t} 应被识别为控制帧");
    }
});

check('ws: unwrapString 两种形态', function () {
    assertSame('abc', \Weixun\StreamFrame::unwrapString(['str' => 'abc']));
    assertSame('abc', \Weixun\StreamFrame::unwrapString('abc'));
    assertSame('', \Weixun\StreamFrame::unwrapString(null));
});

check('ws: 地址构造 http→ws', function () {
    assertSame(
        'ws://127.0.0.1:7006/ws/GetSyncMsg?key=mykey',
        \Weixun\StreamFrame::wsUrl('http://127.0.0.1:7006', 'mykey')
    );
    assertTrue(str_starts_with(
        \Weixun\StreamFrame::wsUrl('https://example.com', 'k'), 'wss://'), 'https 应转 wss');
});

// ── login 状态机 ────────────────────────────────────

check('login: 5 状态机', function () {
    $fx = load('login/01-state-machine.json');
    foreach ($fx['cases'] as $c) {
        $st = \Weixun\LoginState::parse(['loginState' => $c['loginState']]);
        assertSame($c['online'], $st->isOnline(), "{$c['loginState']} is_online");
        assertSame($c['terminal'], $st->isTerminal(), "{$c['loginState']} is_terminal");
    }
});

check('upload: stripDataUri', function () {
    assertSame('abc', \Weixun\UploadService::stripDataUri('data:image/png;base64,abc'));
    assertSame('abc', \Weixun\UploadService::stripDataUri('abc'));
});

echo "\n" . ($failures === 0
    ? "全部通过（{$passed} 项）"
    : "{$failures} 个失败，{$passed} 个通过") . "\n";

exit($failures === 0 ? 0 : 1);
