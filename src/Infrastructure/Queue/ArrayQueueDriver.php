<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * In-memory queue driver for tests. Supports reservation, delayed
 * availability, release (retry), and a dead-letter list.
 */
final class ArrayQueueDriver implements QueueDriver
{
    /** @var array<int, array{id:int,name:string,payload:array<string,mixed>,queue:string,attempts:int,available_at:int}> */
    private array $messages = [];

    /** @var array<int, array<string,mixed>> */
    public array $failed = [];

    private int $seq = 0;

    public function push(string $name, array $payload, string $queue = 'default', int $delaySeconds = 0): void
    {
        $id = ++$this->seq;
        $this->messages[$id] = [
            'id' => $id, 'name' => $name, 'payload' => $payload, 'queue' => $queue,
            'attempts' => 0, 'available_at' => time() + $delaySeconds,
        ];
    }

    public function pop(string $queue = 'default'): ?Message
    {
        $now = time();
        foreach ($this->messages as $row) {
            if ($row['queue'] === $queue && $row['available_at'] <= $now) {
                return new Message($row['name'], $row['payload'], $row['queue'], $row['attempts'], $row['id']);
            }
        }
        return null;
    }

    public function ack(Message $message): void
    {
        unset($this->messages[(int) $message->id]);
    }

    public function release(Message $message, int $delaySeconds): void
    {
        $id = (int) $message->id;
        if (isset($this->messages[$id])) {
            $this->messages[$id]['attempts']++;
            $this->messages[$id]['available_at'] = time() + $delaySeconds;
        }
    }

    public function fail(Message $message, string $error): void
    {
        $id = (int) $message->id;
        $this->failed[] = ['name' => $message->name, 'payload' => $message->payload, 'error' => $error];
        unset($this->messages[$id]);
    }

    public function size(string $queue = 'default'): int
    {
        return count(array_filter($this->messages, static fn ($m) => $m['queue'] === $queue));
    }
}
