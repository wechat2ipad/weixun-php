<?php

declare(strict_types=1);

namespace Weixun;

/**
 * WebSocket 帧解析（纯函数，不负责建连）。
 *
 * 协议端三种帧形态：
 *  1. {"key","message","type":"message"} 普通消息
 *  2. {"type":"record_content", ...} 记录补拉事件
 *  3. "pong" / "sync completed" 裸文本控制帧
 *
 * 建连交给调用方（PHP 需 ext-sockets 或第三方库），本类只做解析，
 * 便于与其它语言共用同一套解析逻辑与测试。
 */
final class StreamFrame
{
    /** 从流里解析出来的一条微信消息。 */
    public static function parseAddMsg(mixed $node): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        return [
            'msg_id' => (int) ($node['msg_id'] ?? 0),
            'new_msg_id' => self::intAsString($node['new_msg_id'] ?? null),
            'from' => self::unwrapString($node['from_user_name'] ?? null),
            'to' => self::unwrapString($node['to_user_name'] ?? null),
            'type' => (int) ($node['msg_type'] ?? 0),
            'content' => self::unwrapString($node['content'] ?? null),
            'create_time' => (int) ($node['create_time'] ?? 0),
            'push_content' => (string) ($node['push_content'] ?? ''),
            'raw' => $node,
        ];
    }

    /**
     * 解析一帧原始文本。
     *
     * @return array{kind:string, message?:array, event?:array}
     *         kind 取值：message / record_content / control / unknown
     */
    public static function parseFrame(string $raw): array
    {
        $text = trim($raw);
        if ($text === '' || in_array($text, ['pong', 'sync completed', 'ping'], true)) {
            return ['kind' => 'control'];
        }
        if ($text[0] !== '{') {
            return ['kind' => 'unknown'];
        }
        $v = json_decode($text, true);
        if (!is_array($v)) {
            return ['kind' => 'unknown'];
        }
        $type = $v['type'] ?? '';
        if ($type === 'message') {
            $m = self::parseAddMsg($v['message'] ?? null);
            return $m === null ? ['kind' => 'unknown'] : ['kind' => 'message', 'message' => $m];
        }
        if ($type === 'record_content') {
            return ['kind' => 'record_content', 'event' => [
                'message_id' => self::intAsString($v['message_id'] ?? null),
                'talker' => (string) ($v['talker'] ?? ''),
                'success' => (bool) ($v['success'] ?? false),
                'content' => (string) ($v['content'] ?? ''),
                'length' => (int) ($v['length'] ?? 0),
                'timestamp' => (int) ($v['timestamp'] ?? 0),
                'error' => (string) ($v['error'] ?? ''),
            ]];
        }
        return ['kind' => 'unknown'];
    }

    /** 处理协议端两种字符串表达：{"str":"xxx"} 或裸字符串。 */
    public static function unwrapString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_array($v) && isset($v['str']) && is_string($v['str'])) {
            return $v['str'];
        }
        return '';
    }

    /** 把整数（数字或字符串）统一转成字符串。 */
    public static function intAsString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v)) {
            return (string) $v;
        }
        return '';
    }

    /** 构造 WebSocket 地址（http→ws / https→wss）。 */
    public static function wsUrl(string $baseUrl, string $licenseKey): string
    {
        $base = $baseUrl;
        if (str_starts_with($base, 'https://')) {
            $base = 'wss://' . substr($base, 8);
        } elseif (str_starts_with($base, 'http://')) {
            $base = 'ws://' . substr($base, 7);
        }
        return rtrim($base, '/') . '/ws/GetSyncMsg?key=' . rawurlencode($licenseKey);
    }
}
