<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Streaming;

/**
 * Configuration for a PTY-backed interactive shell session.
 *
 * The $env map is accepted for API completeness but is NOT applied by the
 * current streaming worker: phpseclib3 does not expose an SSH "env" request
 * for PTY shells, and injecting `export` after the shell opens would pollute
 * the user's first prompt. Set environment variables in the remote shell's
 * startup files (~/.bashrc, ~/.profile) instead.
 */
final class PtyConfig
{
    /**
     * @param array<string, string> $env Reserved for future use; see class docblock.
     */
    public function __construct(
        public readonly string $term = 'xterm-256color',
        public readonly int $cols = 80,
        public readonly int $rows = 24,
        public readonly array $env = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'term' => $this->term,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'env' => $this->env,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            term: (string) ($data['term'] ?? 'xterm-256color'),
            cols: (int) ($data['cols'] ?? 80),
            rows: (int) ($data['rows'] ?? 24),
            env: is_array($data['env'] ?? null) ? $data['env'] : [],
        );
    }
}
