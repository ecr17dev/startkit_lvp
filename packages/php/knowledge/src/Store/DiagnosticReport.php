<?php

namespace Starter\Knowledge\Store;

/**
 * Result of the RAG connection checks, shown as is in the panel.
 */
final class DiagnosticReport
{
    /**
     * @var list<array{key: string, label: string, status: string, message: string, sql?: string}>
     */
    private array $checks = [];

    private ?string $pgvector = null;

    public function add(string $key, string $label, string $status, string $message, ?string $sql = null): self
    {
        $this->checks[] = array_filter(compact('key', 'label', 'status', 'message', 'sql'), fn ($value) => $value !== null);

        return $this;
    }

    public function ok(string $key, string $label, string $message): self
    {
        return $this->add($key, $label, 'ok', $message);
    }

    public function warning(string $key, string $label, string $message, ?string $sql = null): self
    {
        return $this->add($key, $label, 'warning', $message, $sql);
    }

    public function error(string $key, string $label, string $message, ?string $sql = null): self
    {
        return $this->add($key, $label, 'error', $message, $sql);
    }

    public function skipped(string $key, string $label, string $message): self
    {
        return $this->add($key, $label, 'skipped', $message);
    }

    public function setPgvector(?string $version): self
    {
        $this->pgvector = $version;

        return $this;
    }

    public function pgvector(): ?string
    {
        return $this->pgvector;
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['status'] === 'error') {
                return false;
            }
        }

        return $this->checks !== [];
    }

    public function has(string $key): bool
    {
        foreach ($this->checks as $check) {
            if ($check['key'] === $key && $check['status'] === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{passed: bool, pgvector: string|null, checks: list<array{key: string, label: string, status: string, message: string, sql?: string}>, checked_at: string}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'pgvector' => $this->pgvector,
            'checks' => $this->checks,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
