<?php

namespace Starter\Installer;

/**
 * Stable exit codes of `laravel-starter` (documented; scripts and agents
 * may rely on them).
 */
final class ExitCode
{
    public const OK = 0;

    public const UNEXPECTED = 1;

    public const USAGE = 2;

    public const CONFIG_INVALID = 3;

    public const PREREQUISITES = 4;

    public const TARGET_EXISTS = 5;

    public const TEMPLATE = 6;

    public const STEP_FAILED = 7;

    /**
     * @return array<int, string>
     */
    public static function descriptions(): array
    {
        return [
            self::OK => 'Project created (or plan shown with --dry-run)',
            self::UNEXPECTED => 'Unexpected error',
            self::USAGE => 'Invalid arguments or options',
            self::CONFIG_INVALID => 'starter.config.json is not valid',
            self::PREREQUISITES => 'Missing prerequisites or unresolved secret references',
            self::TARGET_EXISTS => 'The target directory exists and is not empty',
            self::TEMPLATE => 'Template unavailable or checksum mismatch',
            self::STEP_FAILED => 'An installation step failed',
        ];
    }
}
