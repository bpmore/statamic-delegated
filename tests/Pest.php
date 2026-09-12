<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\EloquentTestCase;
use Bpmore\StatamicDelegated\Tests\TestCase;

// Everything under Feature gets a booted Statamic. Anything that can be tested
// without one — territory resolution, the escalation invariants' pure parts —
// belongs under Unit and stays fast.
//
// Feature/Eloquent gets the same Statamic with entries in the database (see
// EloquentTestCase). Pest does not let a nested directory override its
// parent's test case, so the flat-file directories are named one by one; a
// new directory under Feature has to be added here.
uses(TestCase::class)->in('Feature/*.php', 'Feature/Invariants', 'Feature/Scoping', 'Feature/CP');
uses(EloquentTestCase::class)->in('Feature/Eloquent');

/**
 * The repository root.
 *
 * Not base_path(): under Testbench that is the skeleton application, not this
 * addon. Resolved from this file, which is the one place guaranteed to be
 * loaded from where it actually lives.
 */
function repoPath(string $relative = ''): string
{
    $root = dirname(__DIR__);

    return $relative === '' ? $root : $root.'/'.$relative;
}

/**
 * PHP files under the given directories, keyed by their path relative to the
 * repository root so a failure names the file.
 *
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function phpSources(array $directories): array
{
    $root = dirname(__DIR__);
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($iterator) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
        }
    }

    ksort($files);

    return $files;
}

/**
 * The namespaced names a PHP file actually references, with comments and
 * string literals excluded.
 *
 * @return list<string>
 */
function referencedNames(string $file): array
{
    $names = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (! is_array($token)) {
            continue;
        }
        if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return $names;
}
