<?php

namespace app\service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AWS 小助理 API 客户端 (api.aws.sb)
 */
class AwsSbService
{
    private $baseUrl;
    private $authToken;

    public function __construct(?string $authToken = null, ?string $baseUrl = null)
    {
        $this->authToken = $authToken ?: (string)config_get('aws_sb_token', '');
        $this->baseUrl = rtrim($baseUrl ?: (string)config_get('aws_sb_api_url', 'https://api.aws.sb'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->authToken !== '';
    }

    private function headers(string $accountId, string $regionName): array
    {
        return [
            'X-Auth-Token' => $this->authToken,
            'X-Account-Id' => $accountId,
            'X-Region-Name' => $regionName,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @throws Exception
     */
    public function getInstancePublicIp(string $accountId, string $regionName, string $instanceId): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('请先在 AWS IP同步设置 中填写 X-Auth-Token');
        }
        if ($accountId === '' || $regionName === '' || $instanceId === '') {
            throw new Exception('AWS 账号、区域或实例 ID 不能为空');
        }

        $url = $this->baseUrl . '/ec2-instances/' . rawurlencode($instanceId);
        $response = $this->request('GET', $url, $this->headers($accountId, $regionName));
        $ip = self::extractPublicIp($response);
        if (!$ip) {
            throw new Exception('未能从 AWS 小助理 API 解析出公网 IPv4');
        }
        return $ip;
    }

    /**
     * @throws Exception
     */
    private function request(string $method, string $url, array $headers, $body = null): array
    {
        $options = [
            'timeout' => 30,
            'verify' => false,
            'http_errors' => false,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $client = new Client();
            $resp = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new Exception('请求 AWS 小助理 API 失败：' . $e->getMessage());
        }

        $raw = (string)$resp->getBody();
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if ($resp->getStatusCode() >= 400) {
            $msg = is_array($data) ? ($data['detail'] ?? $data['msg'] ?? $data['message'] ?? $raw) : $raw;
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            throw new Exception('AWS 小助理 API 错误 HTTP ' . $resp->getStatusCode() . '：' . $msg);
        }
        if (!is_array($data)) {
            throw new Exception('AWS 小助理 API 返回格式异常');
        }
        return $data;
    }

    public static function extractPublicIp($data): ?string
    {
        $candidates = [];
        self::walkIpFields($data, $candidates);
        foreach ($candidates as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
        return null;
    }

    private static function walkIpFields($obj, array &$candidates): void
    {
        if (!is_array($obj)) {
            return;
        }
        foreach ($obj as $key => $value) {
            $kl = strtolower((string)$key);
            if (in_array($kl, [
                'public_ip',
                'publicip',
                'public_ip_address',
                'publicipaddress',
                'ip_address',
                'ipaddress',
                'ipv4',
                'eip',
            ], true) && is_string($value)) {
                $candidates[] = $value;
            } elseif (is_array($value)) {
                self::walkIpFields($value, $candidates);
            }
        }
    }
}
