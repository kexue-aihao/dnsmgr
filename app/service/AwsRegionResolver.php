<?php

namespace app\service;

/**
 * 根据 AWS 官方 IP 段数据解析 EC2 公网 IP 所在 region
 * 数据源: app/data/aws_ip_ranges.json (来自 ip-ranges.xlsx)
 */
class AwsRegionResolver
{
    private static $ranges = null;

    private static $accountRegionHints = [
        '新加坡' => 'ap-southeast-1',
        'singapore' => 'ap-southeast-1',
        '香港' => 'ap-east-1',
        'hongkong' => 'ap-east-1',
        '东京' => 'ap-northeast-1',
        '日本' => 'ap-northeast-1',
        'jp' => 'ap-northeast-1',
        '首尔' => 'ap-northeast-2',
        '韩国' => 'ap-northeast-2',
        '孟买' => 'ap-south-1',
        '印度' => 'ap-south-1',
        '悉尼' => 'ap-southeast-2',
        '澳洲' => 'ap-southeast-2',
        '法兰克福' => 'eu-central-1',
        '伦敦' => 'eu-west-2',
        '巴黎' => 'eu-west-3',
        '弗吉尼亚' => 'us-east-1',
        '美东' => 'us-east-1',
        '俄勒冈' => 'us-west-2',
        '美西' => 'us-west-2',
        '硅谷' => 'us-west-1',
        '台北' => 'ap-east-1',
        '台湾' => 'ap-east-1',
    ];

    public static function resolveFromIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        self::loadRanges();
        foreach (self::$ranges as $entry) {
            if (self::ipInCidr($ip, $entry[0])) {
                return $entry[1];
            }
        }
        return null;
    }

    public static function resolveFromAccountName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $lower = strtolower($name);
        foreach (self::$accountRegionHints as $keyword => $region) {
            if (str_contains($lower, strtolower($keyword))) {
                return $region;
            }
        }
        if (preg_match('/\b(ap|eu|us|sa|ca|me|af|cn)-[a-z]+-\d+\b/', $lower, $m)) {
            return $m[0];
        }
        return null;
    }

    public static function resolve(?string $region, ?string $publicIp, ?string $accountName = null): string
    {
        $region = trim((string)$region);
        if ($region !== '') {
            return $region;
        }
        $fromIp = self::resolveFromIp($publicIp);
        if ($fromIp) {
            return $fromIp;
        }
        $fromName = self::resolveFromAccountName($accountName);
        return $fromName ?: '';
    }

    private static function loadRanges(): void
    {
        if (self::$ranges !== null) {
            return;
        }
        $file = app()->getRootPath() . 'app/data/aws_ip_ranges.json';
        if (!is_file($file)) {
            self::$ranges = [];
            return;
        }
        $data = json_decode((string)file_get_contents($file), true);
        self::$ranges = is_array($data['ranges'] ?? null) ? $data['ranges'] : [];
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) {
            return false;
        }
        $maskBits = (int)$maskBits;
        if ($maskBits <= 0) {
            return true;
        }
        if ($maskBits >= 32) {
            return $ipLong === $subLong;
        }
        $mask = -1 << (32 - $maskBits);
        return ($ipLong & $mask) === ($subLong & $mask);
    }
}
