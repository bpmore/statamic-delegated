<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Console;

use Bpmore\StatamicDelegated\Doctor\Doctor as Checks;
use Bpmore\StatamicDelegated\Doctor\Finding;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `php please delegated:doctor` — what has drifted, and what quietly defeats
 * the territory model. Exit 1 when there is a problem, so it can gate a deploy.
 */
final class Doctor extends Command
{
    use RunsInPlease;

    protected $signature = 'delegated:doctor {--json : Machine-readable findings}';

    protected $description = 'Check Delegated\'s territories, assignments and content for drift and unsafe configuration';

    public function handle(Checks $checks, TerritoryResolver $resolver): int
    {
        $findings = $checks->diagnose();
        $problems = count(array_filter($findings, fn (Finding $f): bool => $f->severity === Finding::PROBLEM));

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $problems === 0,
                'findings' => array_map(fn (Finding $f): array => $f->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        $map = $resolver->map();
        $territories = count($map->all());
        $this->components->info("Delegated: {$territories} ".($territories === 1 ? 'territory' : 'territories'));

        if ($findings === []) {
            $this->components->success('Nothing to report.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            match ($finding->severity) {
                Finding::PROBLEM => $this->components->error($finding->message),
                Finding::WARNING => $this->components->warn($finding->message),
                default => $this->components->info($finding->message),
            };

            $this->components->bulletList($finding->details);
        }

        $warnings = count(array_filter($findings, fn (Finding $f): bool => $f->severity === Finding::WARNING));
        $notes = count($findings) - $problems - $warnings;
        $this->newLine();
        $this->line("  {$problems} problem(s), {$warnings} warning(s), {$notes} note(s).");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
