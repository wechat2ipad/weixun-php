<?php

declare(strict_types=1);

namespace Weixun;

/**
 * 消息服务：发送、群发、撤回，以及逐条结果归一化。
 *
 * 协议端逐条结果字段不统一（实测）：
 *  - 接收方：toUSerName（14 处）/ toUserName（9 处）
 *  - 成功标志 7 种命名
 *  - 错误信息：errMsg / errMgs（拼写错误）
 */
final class MessageService
{
    public const MSG_TYPE_TEXT = 1;
    public const MSG_TYPE_IMAGE = 3;
    public const MSG_TYPE_VOICE = 34;
    public const MSG_TYPE_CARD = 42;
    public const MSG_TYPE_VIDEO = 43;
    public const MSG_TYPE_STICKER = 47;
    public const MSG_TYPE_LOCATION = 48;
    public const MSG_TYPE_APP = 49;
    public const MSG_TYPE_SYSTEM = 10000;
    public const MSG_TYPE_REVOKE = 10002;

    private const SUCCESS_FLAGS = [
        'isSendSuccess', 'isCommentSuccess', 'isMarkSuccess', 'isHideSuccess',
        'isFollowSuccess', 'isUploadSuccess', 'isSuccess',
    ];
    private const TO_KEYS = ['toUSerName', 'toUserName', 'to_user_name'];
    private const ERROR_KEYS = ['errMsg', 'errMgs', 'error'];

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * 发送一条文本消息。
     *
     * 协议端要求请求体是 {"MsgItem": [ {...} ]}，MsgItem 是**数组**。
     */
    public function sendText(string $to, string $text, ?array $at = null): MessageResult
    {
        if ($to === '') {
            throw new \InvalidArgumentException('接收方不能为空');
        }
        $item = [
            'ToUserName' => $to,
            'TextContent' => $text,
            'MsgType' => 1,
        ];
        if (!empty($at)) {
            $item['AtWxIDList'] = $at;
        }
        $data = $this->client->call('POST', '/message/SendTextMessage', ['MsgItem' => [$item]]);
        $results = self::parseBatchResults($data);
        if ($results === []) {
            throw new \RuntimeException('协议端未返回发送结果');
        }
        return $results[0];
    }

    /**
     * 批量发送。
     *
     * @param array<array{0:string,1:string}> $pairs [to, text] 二元组列表
     * @return MessageResult[]
     */
    public function sendTextBatch(array $pairs): array
    {
        $items = [];
        foreach ($pairs as $pair) {
            $items[] = [
                'ToUserName' => $pair[0],
                'TextContent' => $pair[1],
                'MsgType' => 1,
            ];
        }
        $data = $this->client->call('POST', '/message/SendTextMessage', ['MsgItem' => $items]);
        return self::parseBatchResults($data);
    }

    /** 发送图片（base64，可带 data URI 前缀）。 */
    public function sendImage(string $to, string $imageBase64): MessageResult
    {
        $data = $this->client->call('POST', '/message/SendImageMessage', [
            'MsgItem' => [[
                'ToUserName' => $to,
                'ImageContent' => $imageBase64,
                'MsgType' => 2,
            ]],
        ]);
        $results = self::parseBatchResults($data);
        if ($results === []) {
            throw new \RuntimeException('协议端未返回发送结果');
        }
        return $results[0];
    }

    /**
     * 向多个群/联系人批量发送相同文本。
     *
     * 注意：协议端返回微信 MassSendResponse 原样透传，**没有**收件人级别明细。
     */
    public function groupMassText(array $to, string $content): mixed
    {
        if ($to === []) {
            throw new \InvalidArgumentException('收件人列表不能为空');
        }
        return $this->client->call('POST', '/message/GroupMassMsgText', [
            'ToUserName' => $to,
            'Content' => $content,
        ]);
    }

    /** 撤回消息（newMsgId 必须是字符串形式）。 */
    public function revoke(string $to, string $newMsgId, string $clientMsgId): void
    {
        $this->client->call('POST', '/message/RevokeMsg', [
            'ToUserName' => $to,
            'NewMsgId' => $newMsgId,
            'ClientMsgId' => $clientMsgId,
        ]);
    }

    /** 标记会话已读。 */
    public function markAllRead(string $to): void
    {
        $this->client->call('POST', '/message/MarkAllMessagesAsRead', ['ToUserName' => $to]);
    }

    /** 设置 HTTP 消息回调（5 秒超时、不重试）。 */
    public function setCallback(string $callbackUrl): void
    {
        $this->client->call('POST', '/message/SetCallback', ['CallbackUrl' => $callbackUrl]);
    }

    public static function isContactSync(int $msgType): bool
    {
        return $msgType === self::MSG_TYPE_SYSTEM;
    }

    public static function isRevoke(int $msgType): bool
    {
        return $msgType === self::MSG_TYPE_REVOKE;
    }

    /**
     * 把协议端五花八门的逐条结果统一成 MessageResult。
     *
     * @return MessageResult[]
     */
    public static function parseBatchResults(mixed $data): array
    {
        if ($data === null) {
            return [];
        }
        $items = [];
        if (is_array($data) && array_is_list($data)) {
            $items = array_filter($data, 'is_array');
        } elseif (is_array($data)) {
            $items = [$data];
        } else {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $to = '';
            foreach (self::TO_KEYS as $k) {
                if (isset($item[$k]) && is_string($item[$k]) && $item[$k] !== '') {
                    $to = $item[$k];
                    break;
                }
            }
            $err = '';
            foreach (self::ERROR_KEYS as $k) {
                if (isset($item[$k]) && is_string($item[$k]) && $item[$k] !== '') {
                    $err = $item[$k];
                    break;
                }
            }
            $hasFlag = false;
            $ok = false;
            foreach (self::SUCCESS_FLAGS as $f) {
                if (array_key_exists($f, $item)) {
                    $hasFlag = true;
                    if ($item[$f] === true) {
                        $ok = true;
                    }
                }
            }
            if (!$hasFlag) {
                $ok = true;
            }
            if ($err !== '') {
                $ok = false;
            }
            $out[] = new MessageResult($ok, $to, $err, $item);
        }
        return $out;
    }
}
