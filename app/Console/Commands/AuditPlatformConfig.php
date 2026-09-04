<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Audit\ConfigAuditFinding;
use App\Platform\Audit\PlatformConfigAuditor;
use Illuminate\Console\Command;

/**
 * Read-only. Lists configuration gaps a customer would otherwise find:
 * missing webhook secrets, uncovered price-matrix cells, inactive
 * currencies, invalid time zones, instructors with no availability.
 *
 * Exit code 1 when anything FAILS (or, with --strict, WARNS) so it can
 * gate a deploy pipeline.
 */
final class AuditPlatformConfig extends Command
{
    protected $signature = 'platform:audit-config {--json : Machine-readable output} {--strict : Treat warnings as failures}';

    protected $description = 'Audit live configuration data (payments, prices, currencies, time zones, availability) without changing anything.';

    public function handle(PlatformConfigAuditor $auditor): int
    {
        $findings = $auditor->run();

        $fails = $findings->where('severity', ConfigAuditFinding::FAIL)->count();
        $warns = $findings->where('severity', ConfigAuditFinding::WARN)->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'fails' => $fails,
                'warns' => $warns,
                'findings' => $findings->map(fn (ConfigAuditFinding $f): array => $f->toArray())->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Section', 'Status', 'Finding', 'Fix'],
                $findings->map(fn (ConfigAuditFinding $f): array => [
                    $f->section,
                    strtoupper($f->severity),
                    wordwrap($f->message, 70),
                    wordwrap((string) $f->fix, 50),
                ])->all(),
            );

            $this->newLine();
            $this->line(sprintf('%d failure(s), %d warning(s), %d check(s) passed.', $fails, $warns, $findings->where('severity', ConfigAuditFinding::OK)->count()));
        }

        $blocking = $fails + ($this->option('strict') ? $warns : 0);

        return $blocking > 0 ? self::FAILURE : self::SUCCESS;
    }
}
