<?php

declare(strict_types=1);

namespace App\Models;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One durable, deduplicated operational-failure signal (SRS
 * §26.27-§26.29). Written exclusively by `OperationalAlertService` —
 * never directly by a controller, listener, job, or Filament action.
 * "Historical alerts must not be deleted" (requirement #2):
 * `PreventsHardDeletion`, no `PreventsUpdates` since acknowledged/
 * resolved fields legitimately mutate over the alert's lifecycle.
 *
 * A distinct domain from `SuspiciousActivityFlag`: this is evidence of
 * an operational/technical failure, never a suspected-fraud signal —
 * nothing here reads compliance data and nothing there reads this.
 */
class OperationalAlert extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion;

    protected $table = 'operational_alerts';

    protected $fillable = [
        'reference',
        'type',
        'category',
        'severity',
        'status',
        'title',
        'summary',
        'subject_type',
        'subject_id',
        'fingerprint',
        'active_fingerprint',
        'occurrence_count',
        'first_observed_at',
        'last_observed_at',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_by',
        'resolved_at',
        'resolution_reason',
        'metadata',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'type' => OperationalAlertType::class,
            'category' => OperationalAlertCategory::class,
            'severity' => OperationalAlertSeverity::class,
            'status' => OperationalAlertStatus::class,
            'occurrence_count' => 'integer',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
            'version' => 'integer',
        ];
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * A best-effort deep link to the related record's own authorized
     * Filament resource (requirement #7) — never a raw payload dump.
     * Some related resources (the reconciliation-issue queues) have no
     * per-record page, so this falls back to their index; an unmapped
     * or missing subject type/route resolves to null, never an error.
     */
    public function subjectUrl(): ?string
    {
        if ($this->subject_type === null || $this->subject_id === null) {
            return null;
        }

        [$routeName, $parameters] = match ($this->subject_type) {
            Booking::class => ['filament.admin.resources.bookings.edit', ['record' => $this->subject_id]],
            BookingPaymentReconciliationIssue::class => ['filament.admin.resources.booking-payment-reconciliation-issues.index', []],
            InstructorPayoutReconciliationIssue::class => ['filament.admin.resources.instructor-payout-reconciliation-issues.index', []],
            default => [null, []],
        };

        if ($routeName === null || ! Route::has($routeName)) {
            return null;
        }

        return route($routeName, $parameters);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'occurrence_count'])
            ->useLogName('operational_alerts')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
