<?php

namespace Starter\Installer\Template;

use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Starter\Installer\Process\ProcessRunner;
use Throwable;

/**
 * Where the project comes from: a local checkout (development, CI) or a
 * versioned release archive verified with its SHA-256 before extraction.
 * Downloads only use https:// (or file:// for mirrors and tests).
 */
class TemplateSource
{
    public const RELEASE_URL = 'https://github.com/bluesmilecl/startkit_laravel/releases/download/v{version}/laravel-starter-template-{version}.tar.gz';

    /**
     * Paths never copied from a local checkout.
     */
    public const EXCLUDED = ['.git', 'node_modules', 'vendor', '.env', 'public/build', 'public/hot', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/testing', 'storage/app/private', 'storage/app/public', '.phpunit.cache', 'test-results', 'playwright-report'];

    public function __construct(protected ProcessRunner $runner) {}

    public static function releaseUrl(string $version): string
    {
        $pattern = getenv('LARAVEL_STARTER_RELEASE_URL') ?: self::RELEASE_URL;

        return str_replace('{version}', ltrim($version, 'v'), $pattern);
    }

    /**
     * Copy a local checkout: its tracked files when it is a git repository,
     * otherwise everything except dependencies, builds, secrets and data.
     *
     * @return int files copied
     */
    public function copyDirectory(string $source, string $target): int
    {
        $source = rtrim((string) realpath($source), '/');

        if ($source === '' || ! is_file("{$source}/apps/platform/artisan")) {
            throw new TemplateException("{$source} is not a starter checkout (apps/platform/artisan is missing).");
        }

        $files = $this->trackedFiles($source) ?? $this->walk($source);
        $count = 0;

        foreach ($files as $relative) {
            if ($this->excluded($relative) || ! is_file("{$source}/{$relative}")) {
                continue;
            }

            $this->copyFile("{$source}/{$relative}", "{$target}/{$relative}");
            $count++;
        }

        return $count;
    }

    /**
     * Verify an archive against its SHA-256 and extract it.
     *
     * @return int files extracted
     */
    public function extractArchive(string $archive, string $expected, string $target): int
    {
        $expected = strtolower(trim($expected));

        if (! preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new TemplateException('A valid SHA-256 checksum is required to verify the template.');
        }

        $actual = hash_file('sha256', $archive);

        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new TemplateException("Checksum mismatch for {$archive}: expected {$expected}, got {$actual}.");
        }

        $workspace = sys_get_temp_dir().'/laravel-starter-'.bin2hex(random_bytes(6));
        $temporary = "{$workspace}/files";

        try {
            // Decompress first (extracting a .tar.gz directly may yield empty files)...
            mkdir("{$workspace}/archive", 0700, true);
            copy($archive, "{$workspace}/archive/template.tar.gz");
            (new PharData("{$workspace}/archive/template.tar.gz"))->decompress();
            (new PharData("{$workspace}/archive/template.tar"))->extractTo($temporary, null, true);
            $entries = array_values(array_diff(scandir($temporary) ?: [], ['.', '..']));
            $root = count($entries) === 1 && is_dir("{$temporary}/{$entries[0]}") ? "{$temporary}/{$entries[0]}" : $temporary;

            if (! is_file("{$root}/apps/platform/artisan")) {
                throw new TemplateException('The archive is not a starter template (apps/platform/artisan is missing).');
            }

            $count = 0;

            foreach ($this->walk($root) as $relative) {
                $this->copyFile("{$root}/{$relative}", "{$target}/{$relative}");
                $count++;
            }

            return $count;
        } catch (TemplateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TemplateException('The archive cannot be extracted: '.$exception->getMessage());
        } finally {
            $this->remove($workspace);
        }
    }

    /**
     * Download a release archive (and its .sha256 when no checksum is
     * given), verify and extract it.
     *
     * @return int files extracted
     */
    public function download(string $url, ?string $sha256, string $target): int
    {
        if (! preg_match('#^(https|file)://#', $url)) {
            throw new TemplateException('Only https:// (or file://) template URLs are accepted.');
        }

        $archive = sys_get_temp_dir().'/laravel-starter-'.bin2hex(random_bytes(6)).'.tar.gz';

        try {
            $data = @file_get_contents($url);

            if ($data === false || $data === '') {
                throw new TemplateException("The template could not be downloaded from {$url}.");
            }

            file_put_contents($archive, $data);

            if ($sha256 === null) {
                $checksum = @file_get_contents($url.'.sha256');

                if ($checksum === false) {
                    throw new TemplateException("No checksum: pass --sha256 or publish {$url}.sha256.");
                }

                $sha256 = (string) strtok(trim($checksum), " \t");
            }

            return $this->extractArchive($archive, $sha256, $target);
        } finally {
            @unlink($archive);
        }
    }

    /**
     * @return list<string>|null
     */
    protected function trackedFiles(string $source): ?array
    {
        if (! is_dir("{$source}/.git") || $this->runner->find('git') === null) {
            return null;
        }

        $result = $this->runner->run(['git', '-C', $source, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $source);

        return $result['ok'] ? array_values(array_filter(explode("\0", $result['output']))) : null;
    }

    /**
     * @return list<string>
     */
    protected function walk(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);

            if ($file->isFile() && ! $this->excluded($relative)) {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    protected function excluded(string $relative): bool
    {
        // Keep the placeholders that create the storage directories...
        if (str_ends_with($relative, '/.gitignore') && str_contains($relative, 'storage/')) {
            return false;
        }

        foreach (self::EXCLUDED as $pattern) {
            if ($relative === $pattern || str_starts_with($relative, "{$pattern}/") || str_contains($relative, "/{$pattern}/") || str_ends_with($relative, "/{$pattern}")) {
                return true;
            }
        }

        return false;
    }

    protected function copyFile(string $from, string $to): void
    {
        if (! is_dir(dirname($to))) {
            mkdir(dirname($to), 0755, true);
        }

        copy($from, $to);
        @chmod($to, fileperms($from) & 0777);
    }

    protected function remove(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
