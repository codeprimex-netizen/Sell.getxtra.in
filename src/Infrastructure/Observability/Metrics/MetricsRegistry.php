<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Metrics;

/**
 * A minimal, dependency-free Prometheus metrics registry (Req 15.2).
 *
 * Supports counters, gauges, and histograms and renders the text exposition
 * format scraped at /metrics. Series are persisted to a JSON file so that
 * counts accumulate across the per-request PHP process model; in production
 * this is swapped for an APCu/Redis-backed store behind the same shape.
 */
final class MetricsRegistry
{
    /** Default latency buckets, in seconds. */
    private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    /** @var array<string, array{help:string,type:string}> */
    private array $meta = [];

    public function __construct(private string $storagePath)
    {
        $dir = dirname($this->storagePath);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function describe(string $name, string $type, string $help): void
    {
        $this->meta[$name] = ['help' => $help, 'type' => $type];
    }

    /** @param array<string,string|int> $labels */
    public function counter(string $name, array $labels = [], float $by = 1.0): void
    {
        $this->mutate(static function (array &$data) use ($name, $labels, $by): void {
            $id = self::series($name, $labels);
            $data['counters'][$id]['name'] = $name;
            $data['counters'][$id]['labels'] = $labels;
            $data['counters'][$id]['value'] = ($data['counters'][$id]['value'] ?? 0) + $by;
        });
    }

    /** @param array<string,string|int> $labels */
    public function gauge(string $name, float $value, array $labels = []): void
    {
        $this->mutate(static function (array &$data) use ($name, $labels, $value): void {
            $id = self::series($name, $labels);
            $data['gauges'][$id] = ['name' => $name, 'labels' => $labels, 'value' => $value];
        });
    }

    /** Record a histogram observation (e.g. request latency in seconds). @param array<string,string|int> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $this->mutate(static function (array &$data) use ($name, $labels, $value): void {
            $id = self::series($name, $labels);
            $h = $data['histograms'][$id] ?? ['name' => $name, 'labels' => $labels, 'buckets' => [], 'sum' => 0.0, 'count' => 0];
            foreach (self::BUCKETS as $b) {
                $key = (string) $b;
                $h['buckets'][$key] = ($h['buckets'][$key] ?? 0) + ($value <= $b ? 1 : 0);
            }
            $h['sum'] += $value;
            $h['count']++;
            $data['histograms'][$id] = $h;
        });
    }

    /** Render the Prometheus text exposition format. */
    public function render(): string
    {
        $data = $this->read();
        $lines = [];
        $emitted = [];

        $header = function (string $name, string $type) use (&$lines, &$emitted): void {
            if (isset($emitted[$name])) {
                return;
            }
            $emitted[$name] = true;
            $help = $this->meta[$name]['help'] ?? $name;
            $declared = $this->meta[$name]['type'] ?? $type;
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} {$declared}";
        };

        foreach ($data['counters'] ?? [] as $c) {
            $header($c['name'], 'counter');
            $lines[] = $c['name'] . self::labelStr($c['labels']) . ' ' . self::num($c['value']);
        }
        foreach ($data['gauges'] ?? [] as $g) {
            $header($g['name'], 'gauge');
            $lines[] = $g['name'] . self::labelStr($g['labels']) . ' ' . self::num($g['value']);
        }
        foreach ($data['histograms'] ?? [] as $h) {
            $header($h['name'], 'histogram');
            $cumulative = 0;
            foreach (self::BUCKETS as $b) {
                $cumulative = $h['buckets'][(string) $b] ?? $cumulative;
                $lines[] = $h['name'] . '_bucket' . self::labelStr($h['labels'] + ['le' => (string) $b]) . ' ' . self::num($cumulative);
            }
            $lines[] = $h['name'] . '_bucket' . self::labelStr($h['labels'] + ['le' => '+Inf']) . ' ' . self::num($h['count']);
            $lines[] = $h['name'] . '_sum' . self::labelStr($h['labels']) . ' ' . self::num($h['sum']);
            $lines[] = $h['name'] . '_count' . self::labelStr($h['labels']) . ' ' . self::num($h['count']);
        }

        return implode("\n", $lines) . "\n";
    }

    public function reset(): void
    {
        if (is_file($this->storagePath)) {
            @unlink($this->storagePath);
        }
    }

    // ── internals ──────────────────────────────────────────────────

    /** @param callable(array<string,mixed>):void $fn */
    private function mutate(callable $fn): void
    {
        $fp = @fopen($this->storagePath, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            @flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp) ?: '';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = ['counters' => [], 'gauges' => [], 'histograms' => []];
            }
            $fn($data);
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string) json_encode($data));
            fflush($fp);
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if (!is_file($this->storagePath)) {
            return ['counters' => [], 'gauges' => [], 'histograms' => []];
        }
        $data = json_decode((string) file_get_contents($this->storagePath), true);
        return is_array($data) ? $data : ['counters' => [], 'gauges' => [], 'histograms' => []];
    }

    /** @param array<string,string|int> $labels */
    private static function series(string $name, array $labels): string
    {
        ksort($labels);
        return $name . '|' . json_encode($labels);
    }

    /** @param array<string,string|int> $labels */
    private static function labelStr(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        ksort($labels);
        $parts = [];
        foreach ($labels as $k => $v) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v);
            $parts[] = $k . '="' . $escaped . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function num(float|int $n): string
    {
        if (is_int($n) || $n == (int) $n) {
            return (string) (int) $n;
        }
        return rtrim(rtrim(sprintf('%.6f', $n), '0'), '.');
    }
}
