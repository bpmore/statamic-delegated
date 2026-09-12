<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Doctor;

/** One thing the doctor found. */
final class Finding
{
    public const PROBLEM = 'problem';

    public const WARNING = 'warning';

    public const NOTE = 'note';

    /**
     * @param  string  $check  a stable slug naming the check, for scripts reading `--json`
     * @param  list<string>  $details  one line per affected thing, for the reader
     */
    public function __construct(
        public readonly string $check,
        public readonly string $severity,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    /** @param  list<string>  $details */
    public static function problem(string $check, string $message, array $details = []): self
    {
        return new self($check, self::PROBLEM, $message, $details);
    }

    /** @param  list<string>  $details */
    public static function warning(string $check, string $message, array $details = []): self
    {
        return new self($check, self::WARNING, $message, $details);
    }

    /** @param  list<string>  $details */
    public static function note(string $check, string $message, array $details = []): self
    {
        return new self($check, self::NOTE, $message, $details);
    }

    /** @return array{check: string, severity: string, message: string, details: list<string>} */
    public function toArray(): array
    {
        return ['check' => $this->check, 'severity' => $this->severity, 'message' => $this->message, 'details' => $this->details];
    }
}
