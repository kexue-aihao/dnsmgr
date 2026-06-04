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

    private function authHeaders(?string $accountId = null, ?string $regionName = null): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('请先在 AWS IP同步设置 中填写 X-Auth-Token');
        }
        $headers = [
            'X-Auth-Token' => $this->authToken,
            'Accept' => 'application/json',
        ];
        if ($accountId !== null && $accountId !== '') {
            $headers['X-Account-Id'] = $accountId;
        }
        if ($regionName !== null && $regionName !== '') {
            $headers['X-Region-Name'] = $regionName;
        }
        return $headers;
    }

    /**
     * @throws Exception
     */
    public function getAccounts(): array
    {
        $url = $this->baseUrl . '/accounts';
        $data = $this->request('GET', $url, $this->authHeaders());
        $list = [];
        foreach (self::extractList($data) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::parseAccount($row);
            if ($item) {
                $list[$item['id']] = $item;
            }
        }
        return array_values($list);
    }

    /**
     * @throws Exception
     */
    public function getRegions(string $accountId): array
    {
        $url = $this->baseUrl . '/accounts/' . rawurlencode($accountId) . '/regions';
        $data = $this->request('GET', $url, $this->authHeaders($accountId));
        $regions = [];
        foreach (self::extractList($data) as $row) {
            if (is_string($row) && $row !== '') {
                $regions[] = $row;
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $name = $row['region_name'] ?? $row['name'] ?? $row['region'] ?? $row['RegionName'] ?? null;
            if ($name) {
                $regions[] = (string)$name;
            }
        }
        return array_values(array_unique($regions));
    }

    /**
     * @throws Exception
     */
    public function listAllInstances(?array $accounts = null, int $timeBudgetSec = 45): array
    {
        $accounts = $accounts ?? $this->getAccounts();
        if (empty($accounts)) {
            throw new Exception('未获取到任何 AWS 账号，请检查 Token 是否正确');
        }
        $deadline = time() + max(5, $timeBudgetSec);
        $all = [];
        foreach ($accounts as $account) {
            if (time() >= $deadline) {
                break;
            }
            $aid = trim((string)($account['id'] ?? ''));
            if ($aid === '') {
                continue;
            }
            $remaining = max(3, $deadline - time());
            $name = (string)($account['name'] ?? $aid);
            foreach ($this->listInstancesByAccount($aid, $name, $remaining) as $item) {
                $all[$item['instance_id']] = $item;
            }
        }
        return array_values($all);
    }

    /**
     * @throws Exception
     */
    public function listInstancesByAccount(string $accountId, ?string $accountName = null, int $timeBudgetSec = 30): array
    {
        $accountName = $accountName ?: $accountId;
        $result = [];
        $seen = [];
        $deadline = time() + max(3, $timeBudgetSec);

        // 优先不带 region 拉取（通常最快）
        try {
            foreach ($this->fetchEc2Instances($accountId, '') as $row) {
                $item = self::parseInstance($row, $accountId, $accountName, '');
                if (!$item || isset($seen[$item['instance_id']])) {
                    continue;
                }
                $seen[$item['instance_id']] = true;
                $result[] = $item;
            }
            if (!empty($result)) {
                return $result;
            }
        } catch (Exception $e) {
            // 继续尝试按 region 拉取
        }

        $tryRegions = [];
        try {
            foreach ($this->getRegions($accountId) as $region) {
                if ($region !== '') {
                    $tryRegions[] = $region;
                }
            }
        } catch (Exception $e) {
            // 部分账号可能无法读取 regions
        }

        foreach ($tryRegions as $region) {
            if (time() >= $deadline) {
                break;
            }
            try {
                $rows = $this->fetchEc2Instances($accountId, $region);
            } catch (Exception $e) {
                continue;
            }
            foreach ($rows as $row) {
                $item = self::parseInstance($row, $accountId, $accountName, $region);
                if (!$item || isset($seen[$item['instance_id']])) {
                    continue;
                }
                $seen[$item['instance_id']] = true;
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function getInstancePublicIp(string $accountId, string $regionName, string $instanceId): string
    {
        if ($accountId === '' || $regionName === '' || $instanceId === '') {
            throw new Exception('AWS 账号、区域或实例 ID 不能为空');
        }

        $url = $this->baseUrl . '/ec2-instances/' . rawurlencode($instanceId);
        $response = $this->request('GET', $url, $this->authHeaders($accountId, $regionName));
        $ip = self::extractPublicIp($response);
        if (!$ip) {
            throw new Exception('未能从 AWS 小助理 API 解析出公网 IPv4');
        }
        return $ip;
    }

    /**
     * @throws Exception
     */
    private function fetchEc2Instances(string $accountId, string $regionName): array
    {
        $url = $this->baseUrl . '/ec2-instances';
        $data = $this->request('GET', $url, $this->authHeaders($accountId, $regionName));
        return self::extractList($data);
    }

    /**
     * @throws Exception
     */
    private function request(string $method, string $url, array $headers, $body = null): array
    {
        $options = [
            'timeout' => 12,
            'connect_timeout' => 5,
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
            $hint = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'Timeout')
                ? '（DNS 服务器访问 api.aws.sb 超时，请检查出站网络或 API 地址）'
                : '';
            throw new Exception('请求 AWS 小助理 API 失败：' . $e->getMessage() . $hint);
        }

        $raw = (string)$resp->getBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if ($resp->getStatusCode() >= 400) {
            $msg = is_array($data) ? ($data['detail'] ?? $data['error'] ?? $data['msg'] ?? $data['message'] ?? $raw) : $raw;
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

    private static function extractList(array $data): array
    {
        if ($data === []) {
            return [];
        }
        if (self::isListArray($data)) {
            return $data;
        }
        foreach (['accounts', 'instances', 'regions', 'data', 'items', 'list', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        return [$data];
    }

    private static function isListArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }
        return array_keys($data) === range(0, count($data) - 1);
    }

    private static function parseAccount(array $row): ?array
    {
        $id = $row['account_id'] ?? $row['id'] ?? $row['AccountId'] ?? $row['accountId'] ?? null;
        $name = $row['name'] ?? $row['account_name'] ?? $row['label'] ?? $row['remark'] ?? null;
        if (($id === null || $id === '') && is_string($name) && str_contains($name, '+')) {
            $parts = explode('+', $name, 2);
            $id = $parts[1] ?? null;
        }
        if ($id === null || $id === '') {
            return null;
        }
        if ($name === null || $name === '') {
            $name = $id;
        }
        return [
            'id' => (string)$id,
            'name' => (string)$name,
        ];
    }

    private static function parseInstance(array $row, string $accountId, string $accountName, string $fallbackRegion): ?array
    {
        $id = $row['instance_id'] ?? $row['InstanceId'] ?? $row['id'] ?? null;
        if ($id === null || !preg_match('/^i-[0-9a-f]+$/i', (string)$id)) {
            return null;
        }
        $region = $row['region_name'] ?? $row['region'] ?? $row['RegionName'] ?? $fallbackRegion;
        $name = $row['name'] ?? $row['instance_name'] ?? $row['InstanceName'] ?? (string)$id;
        return [
            'account_id' => $accountId,
            'account_name' => $accountName,
            'instance_id' => (string)$id,
            'region' => (string)$region,
            'name' => (string)$name,
            'public_ip' => self::extractPublicIp($row) ?: '',
        ];
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
