<?php

declare(strict_types=1);

namespace Weixun;

/**
 * 上传：multipart 大文件 + base64 辅助。
 *
 * 协议端唯一 multipart 接口是 /message/CdnBigFileUpload，其余全部 JSON + base64。
 */
final class UploadService
{
    // 协议端 fileType 取值
    public const FILE_TYPE_IMAGE = 2;
    public const FILE_TYPE_VIDEO = 4;
    public const FILE_TYPE_FILE = 5;

    public function __construct(private readonly Client $client)
    {
    }

    /** 上传大文件（multipart，≤200MB）。 */
    public function bigFile(
        string $filePath,
        int $fileType = self::FILE_TYPE_FILE,
        string $toUser = ''
    ): mixed {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException("文件不存在: {$filePath}");
        }
        if (filesize($filePath) > 200 * 1024 * 1024) {
            throw new \InvalidArgumentException('文件超过 200MB 上限');
        }

        $boundary = '----WeixunBoundary' . bin2hex(random_bytes(16));
        $body = '';
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . basename($filePath) . "\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= file_get_contents($filePath) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"fileType\"\r\n\r\n{$fileType}\r\n";
        if ($toUser !== '') {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"toUser\"\r\n\r\n{$toUser}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $url = rtrim($this->client->baseUrl(), '/')
            . '/message/CdnBigFileUpload?key=' . rawurlencode($this->client->rawKey());
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                . "Content-Length: " . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 120,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new APIError(Envelope::CODE_FAIL_GENERIC, '上传失败');
        }
        $env = json_decode($raw, true);
        if (!is_array($env)) {
            throw new APIError(Envelope::CODE_FAIL_GENERIC, '上传响应非法', '', 200, $raw);
        }
        if (!Envelope::isOk($env)) {
            throw Envelope::error($env, 200, $raw);
        }
        return $env['Data'] ?? null;
    }

    /** 读取本地文件并编码为 base64。 */
    public static function base64FromFile(string $path): string
    {
        return base64_encode((string) file_get_contents($path));
    }

    /** 生成协议端可直接接受的 data URI。 */
    public static function imageDataUri(string $mime, string $bytes): string
    {
        $m = $mime !== '' ? $mime : 'image/png';
        return "data:{$m};base64," . base64_encode($bytes);
    }

    /** 去掉 data URI 前缀，只留纯 base64。 */
    public static function stripDataUri(string $v): string
    {
        if (str_starts_with($v, 'data:') && ($pos = strpos($v, ',')) !== false) {
            return substr($v, $pos + 1);
        }
        return $v;
    }

    /** 上传图片到微信 CDN。 */
    public function uploadImageToCdn(string $imageBase64): mixed
    {
        return $this->client->call('POST', '/message/UploadImageToCDN', ['imageContent' => $imageBase64]);
    }

    /** 上传头像。 */
    public function uploadHeadImage(string $imageBase64): void
    {
        $this->client->call('POST', '/user/UploadHeadImage', ['Base64' => $imageBase64]);
    }

    /** 发送语音（base64）。 */
    public function sendVoice(string $to, string $voiceBase64, int $second): void
    {
        $this->client->call('POST', '/message/SendVoice', [
            'ToUserName' => $to,
            'VoiceData' => $voiceBase64,
            'VoiceSecond' => $second,
        ]);
    }
}
