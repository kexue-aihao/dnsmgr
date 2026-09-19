<?php
declare(strict_types=1);

// No AWS credentials or network access: exercise the adapter against an in-memory transport.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/app/common.php';

use app\lib\client\AWS;
use app\lib\dns\aws as Route53;

final class Route53TestTransport extends AWS
{
    public array $sets = [];
    public array $changes = [];
    public ?string $rejectType = null;

    public function __construct(array $sets)
    {
        foreach ($sets as $set) {
            $this->sets[$this->key($set['Name'], $set['Type'])] = $set;
        }
    }

    private function key(string $name, string $type): string
    {
        return strtolower($name) . '|' . $type;
    }

    public function requestXmlN($method, $path, $params = [], $xml = null, $etag = false)
    {
        if ($method === 'GET') {
            $sets = isset($params['name'])
                ? array_filter($this->sets, fn($set) => $this->key($set['Name'], $set['Type']) === $this->key($params['name'], $params['type']))
                : $this->sets;
            return ['ResourceRecordSets' => ['ResourceRecordSet' => array_values($sets)], 'IsTruncated' => 'false'];
        }
        if ($method !== 'POST' || !is_string($params)) {
            throw new RuntimeException('Unexpected Route 53 request');
        }
        $batch = simplexml_load_string($params);
        if ($batch === false) {
            throw new RuntimeException('Invalid Route 53 XML');
        }
        $this->changes[] = $params;
        $next = $this->sets;
        foreach ($batch->ChangeBatch->Changes->Change as $change) {
            $set = $change->ResourceRecordSet;
            $type = (string) $set->Type;
            if ($type === $this->rejectType) {
                throw new RuntimeException('Simulated Route 53 validation failure');
            }
            $key = $this->key((string) $set->Name, $type);
            if ((string) $change->Action === 'DELETE') {
                unset($next[$key]);
                continue;
            }
            $values = [];
            foreach ($set->ResourceRecords->ResourceRecord as $record) {
                $values[] = (string) $record->Value;
            }
            $next[$key] = route53Set((string) $set->Name, $type, $values, (int) $set->TTL);
        }
        $this->sets = $next;
        return ['ChangeInfo' => ['Id' => 'test-change', 'Status' => 'PENDING']];
    }
}

function route53Set(string $name, string $type, array $values, int $ttl = 900): array
{
    return [
        'Name' => $name, 'Type' => $type, 'TTL' => $ttl,
        'ResourceRecords' => ['ResourceRecord' => array_map(fn($value) => ['Value' => $value], $values)],
    ];
}

function route53Fixture(array $sets): array
{
    $transport = new Route53TestTransport($sets);
    $dns = new Route53(['AccessKeyId' => 'test', 'SecretAccessKey' => 'test', 'domain' => 'example.test', 'domainid' => 'ZTEST']);
    (new ReflectionProperty(Route53::class, 'client'))->setValue($dns, $transport);
    return [$dns, $transport];
}

function route53Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tests = [
    'subdomain type filtering and actual TTL' => function (): void {
        [$dns] = route53Fixture([
            route53Set('www.example.test.', 'A', ['192.0.2.1']),
            route53Set('www.example.test.', 'AAAA', ['2001:db8::1']),
        ]);
        $records = $dns->getSubDomainRecords('www', 1, 100, 'A');
        route53Assert($records['total'] === 1 && $records['list'][0]['Type'] === 'A', 'A-only task selector included another type');
        $info = $dns->getDomainRecordInfo($records['list'][0]['RecordId']);
        route53Assert($info['TTL'] === 900, 'Task cached an invented TTL');
        route53Assert($dns->getDomainRecords(1, 100, null, null, null, null, null, '0')['total'] === 0, 'Disabled-record filter returned active records');
    },
    'repeated value changes preserve sibling records' => function (): void {
        [$dns] = route53Fixture([route53Set('www.example.test.', 'A', ['192.0.2.1', '192.0.2.2'])]);
        $old = $dns->getDomainRecords()['list'][0]['RecordId'];
        $new = $dns->updateDomainRecord($old, 'www', 'A', '192.0.2.3', 'default', 120);
        route53Assert(is_string($new) && $new !== $old, 'Changed value did not return its new record ID');
        route53Assert($dns->getDomainRecordInfo($old) === false, 'Old ID still resolved to stale cached data');
        route53Assert($dns->getDomainRecordInfo($new)['TTL'] === 120, 'Updated TTL was not read back');
        $next = $dns->updateDomainRecord($new, 'www', 'A', '192.0.2.4', 'default', 120);
        route53Assert(is_string($next), 'Second scheduled change failed');
        $values = array_column($dns->getDomainRecords()['list'], 'Value');
        sort($values);
        route53Assert($values === ['192.0.2.2', '192.0.2.4'], 'Update removed a sibling or appended an unwanted A record');
    },
    'stale task IDs cannot create records' => function (): void {
        [$dns, $transport] = route53Fixture([route53Set('www.example.test.', 'A', ['192.0.2.1'])]);
        $old = $dns->getDomainRecords()['list'][0]['RecordId'];
        $transport->sets['www.example.test.|A'] = route53Set('www.example.test.', 'A', ['192.0.2.9']);
        route53Assert($dns->updateDomainRecord($old, 'www', 'A', '192.0.2.3') === false, 'Stale ID was accepted');
        route53Assert($transport->changes === [], 'Stale ID sent a DNS mutation');
        route53Assert($dns->getDomainRecords()['list'][0]['Value'] === '192.0.2.9', 'Existing DNS value changed');
    },
    'type changes are atomic on failure and success' => function (): void {
        [$dns, $transport] = route53Fixture([route53Set('www.example.test.', 'A', ['192.0.2.1'])]);
        $old = $dns->getDomainRecords()['list'][0]['RecordId'];
        $before = $transport->sets;
        $transport->rejectType = 'AAAA';
        route53Assert($dns->updateDomainRecord($old, 'www', 'AAAA', '2001:db8::1') === false, 'Simulated provider rejection was ignored');
        route53Assert($transport->sets === $before, 'Failed type change deleted the original record');
        route53Assert(count($transport->changes) === 1, 'Type change used separate delete/add requests');
        $transport->rejectType = null;
        $new = $dns->updateDomainRecord($old, 'www', 'AAAA', '2001:db8::1');
        route53Assert(is_string($new), 'Valid type change failed');
        $records = $dns->getDomainRecords()['list'];
        route53Assert(count($records) === 1 && $records[0]['Type'] === 'AAAA', 'Type change left the old record behind');
    },
    'advanced routing records cannot be overwritten' => function (): void {
        $alias = ['Name' => 'www.example.test.', 'Type' => 'A', 'AliasTarget' => ['DNSName' => 'target.example.test.']];
        [$dns, $transport] = route53Fixture([$alias]);
        route53Assert($dns->addDomainRecord('www', 'A', '192.0.2.1') === false, 'Alias record was overwritten as a simple record');
        route53Assert($transport->changes === [], 'Unsupported routing sent a DNS mutation');
    },
    'long encoded record IDs round trip' => function (): void {
        $name = str_repeat('a', 63) . '.' . str_repeat('b', 30);
        [$dns] = route53Fixture([route53Set($name . '.example.test.', 'A', ['192.0.2.1'])]);
        $id = $dns->getDomainRecords()['list'][0]['RecordId'];
        route53Assert(strlen($id) > 60, 'Long-ID fixture is ineffective');
        route53Assert($dns->getDomainRecordInfo($id)['Name'] === $name, 'Long record ID did not round trip');
    },
];

try {
    foreach ($tests as $name => $test) {
        $test();
        echo "PASS $name\n";
    }
    echo count($tests) . " Route 53 compatibility tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
