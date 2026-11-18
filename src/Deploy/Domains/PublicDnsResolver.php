<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** @internal */
final class PublicDnsResolver
{
    public function __construct(private readonly ClientInterface $http)
    {
    }

    public function zone(string $name): string|false
    {
        $labels = explode('.', Hostname::normalize($name));
        while (count($labels) >= 1) {
            $candidate = implode('.', $labels);
            $data = $this->query($candidate, 'SOA', 6);
            if ($data === false) {
                return false;
            }
            if (($data['Status'] ?? null) === 0) {
                $answers = $data['Answer'] ?? [];
                if (! is_array($answers) || ! array_is_list($answers)) {
                    return false;
                }
                foreach ($answers as $entry) {
                    $record = Aws::map($entry);
                    $host = Aws::string($record['name'] ?? null);
                    $type = $record['type'] ?? null;
                    if ($host === null || ! is_int($type)) {
                        return false;
                    }
                    if ($type === 6 && Hostname::same($host, $candidate)) {
                        if (Aws::string($record['data'] ?? null) === null) {
                            return false;
                        }
                        return $candidate;
                    }
                }
            }
            array_shift($labels);
        }
        return false;
    }

    private const TYPES = ['A' => 1, 'CNAME' => 5, 'AAAA' => 28];

    /**
     * Every answer to a query for $name and $type (A, AAAA or CNAME), the CNAMEs followed on the way
     * included: [] when the name does not exist or has no such record, false when the answer is not certain.
     *
     * @return list<array{host: string, type: string, target: string}>|false
     */
    public function records(string $name, string $type): array|false
    {
        $typeId = self::TYPES[$type] ?? null;
        $data = $typeId === null ? false : $this->query($name, $type, $typeId);
        if ($data === false) {
            return false;
        }
        $answers = $data['Answer'] ?? [];
        if (! is_array($answers) || ! array_is_list($answers)) {
            return false;
        }
        $names = array_flip(self::TYPES);
        $records = [];
        foreach ($answers as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            $record = Aws::map($entry);
            $host = Aws::string($record['name'] ?? null);
            $value = Aws::string($record['data'] ?? null);
            $answerType = $record['type'] ?? null;
            if ($host === null || $value === null || ! is_int($answerType)) {
                return false;
            }
            $records[] = ['host' => $host, 'type' => $names[$answerType] ?? (string) $answerType, 'target' => $value];
        }
        return $records;
    }

    /** @return list<array<string, string>>|false */
    public function resolve(string $name): array|false
    {
        $data = $this->query($name, 'CNAME', 5);
        if ($data === false || ($data['Status'] ?? null) !== 0 || ! is_array($data['Answer'] ?? null)) {
            return false;
        }
        $records = [];
        foreach ($data['Answer'] as $entry) {
            $record = Aws::map($entry);
            $host = Aws::string($record['name'] ?? null);
            $target = Aws::string($record['data'] ?? null);
            if (($record['type'] ?? null) !== 5 || $host === null || $target === null) {
                return false;
            }
            $records[] = ['host' => $host, 'type' => 'CNAME', 'target' => $target];
        }
        return $records;
    }

    /** @return array<string, mixed>|false */
    private function query(string $name, string $type, int $typeId): array|false
    {
        try {
            $response = $this->http->request('GET', 'https://cloudflare-dns.com/dns-query', [
                'headers' => ['Accept' => 'application/dns-json'],
                'query' => ['name' => $name, 'type' => $type],
                'timeout' => 10,
                'connect_timeout' => 5,
                'allow_redirects' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                return false;
            }
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException|\JsonException) {
            return false;
        }
        if (! is_array($data) || ! in_array($data['Status'] ?? null, [0, 3], true) || ($data['TC'] ?? null) !== false
            || ! is_array($data['Question'] ?? null) || count($data['Question']) !== 1) {
            return false;
        }
        $question = Aws::map($data['Question'][0] ?? null);
        $questionName = Aws::string($question['name'] ?? null);
        if (($question['type'] ?? null) !== $typeId || $questionName === null || ! Hostname::same($questionName, $name)) {
            return false;
        }
        return Aws::map($data);
    }
}