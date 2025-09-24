<?php
declare(strict_types=1);

namespace Queue;

final class WorkItem
{
    public int $id;
    public string $type;
    /** @var array<string,mixed> */
    public array $payload;
    public string $status;
    public int $attempts;
    public ?string $started_at;
    public ?string $finished_at;
}
