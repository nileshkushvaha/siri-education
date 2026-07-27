<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-compatible reconciliation for any database that ran an
 * earlier version of the `bookings`/`booking_types` migrations under
 * their original content, before those files were rewritten in place
 * (authenticated-only cleanup; type-scope alignment). Editing an
 * already-applied migration's `up()` has no
 * effect on a database that already recorded it as run — only a true
 * `migrate:fresh` picks up the new content, and that command is
 * deliberately blocked outside the `testing` environment
 * (AppServiceProvider::boot()). Every step below is guarded so this
 * migration is a no-op on a database already built from the current
 * migration set.
 *
 * No legacy-data requirement — both phases were explicit that dev/
 * testing databases may be rebuilt, guest bookings and removed
 * booking types carry no data-preservation obligation. Bookings of a
 * type being removed, or with no student, are deleted outright along
 * with their dependent rows (never reassigned or backfilled).
 */
return new class extends Migration
{
    private const array APPROVED_TYPE_KEYS = ['free_demo', 'paid_one_to_one'];

    /** Every table with a booking_id FK (information_schema.KEY_COLUMN_USAGE), deleted before the parent row. */
    private const array BOOKING_ID_CHILD_TABLES = [
        'booking_activities',
        'booking_guests',
        'booking_meetings',
        'booking_payments',
        'homework_assignments',
        'instructor_compensation_exceptions',
        'instructor_earnings',
        'instructor_student_feedback',
        'lesson_attendance_records',
        'lesson_financial_dispositions',
        'lesson_review_eligibilities',
        'lesson_reviews',
        'lessons',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        $this->removeBookingsForRemovedTypes();
        $this->removeGuestBookings();
        $this->dropLegacyGuestCheckConstraint();
        $this->dropGuestColumns();
        $this->renameActorColumnsAndEnforceNotNull();

        if (Schema::hasTable('booking_guests')) {
            Schema::drop('booking_guests');
        }

        $this->removeObsoleteBookingTypes();
    }

    public function down(): void
    {
        // Irreversible by design — this migration deletes data (removed-
        // type and guest bookings) that has no schema to roll back into.
    }

    private function removeBookingsForRemovedTypes(): void
    {
        if (! Schema::hasTable('booking_types')) {
            return;
        }

        $ids = DB::table('bookings')
            ->join('booking_types', 'bookings.booking_type_id', '=', 'booking_types.id')
            ->whereNotIn('booking_types.key', self::APPROVED_TYPE_KEYS)
            ->pluck('bookings.id');

        $this->deleteBookings($ids);
    }

    private function removeGuestBookings(): void
    {
        $studentColumn = Schema::hasColumn('bookings', 'student_id') ? 'student_id' : 'attendee_id';

        if (! Schema::hasColumn('bookings', $studentColumn)) {
            return;
        }

        $ids = DB::table('bookings')->whereNull($studentColumn)->pluck('id');

        $this->deleteBookings($ids);
    }

    /** Pre-17U.3's `(attendee_id IS NOT NULL) OR (guest_email IS NOT NULL)` guard — its own column is being dropped. */
    private function dropLegacyGuestCheckConstraint(): void
    {
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'bookings')
            ->where('CONSTRAINT_NAME', 'chk_bookings_attendee_or_guest')
            ->exists();

        if ($exists) {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_bookings_attendee_or_guest');
        }
    }

    private function dropGuestColumns(): void
    {
        foreach (['guest_name', 'guest_email', 'guest_phone', 'manage_token'] as $column) {
            if (Schema::hasColumn('bookings', $column)) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function renameActorColumnsAndEnforceNotNull(): void
    {
        // Renaming a column that participates in a foreign key gives
        // contradictory MySQL errors no matter which ALGORITHM is
        // requested (COPY refuses because of the FK and suggests INPLACE;
        // INPLACE then refuses the same statement and suggests COPY) — so
        // the FK is dropped first, the column renamed and set NOT NULL
        // with no FK attached, then the same FK (RESTRICT on delete, per
        // the pre-17U.3 baseline) is recreated under its new column name.
        // Every remaining row was already narrowed to a non-null
        // attendee_id by removeGuestBookings() above, so NOT NULL is
        // always safe here.
        $this->renameActorColumn('attendee_id', 'student_id', 'bookings_attendee_id_foreign');
        $this->renameActorColumn('host_id', 'instructor_id', 'bookings_host_id_foreign');
    }

    private function renameActorColumn(string $from, string $to, string $foreignKeyName): void
    {
        if (! Schema::hasColumn('bookings', $from) || Schema::hasColumn('bookings', $to)) {
            return;
        }

        $hasForeignKey = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'bookings')
            ->where('CONSTRAINT_NAME', $foreignKeyName)
            ->exists();

        if ($hasForeignKey) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropForeign($foreignKeyName));
        }

        DB::statement("ALTER TABLE bookings RENAME COLUMN {$from} TO {$to}");
        DB::statement("ALTER TABLE bookings MODIFY {$to} BIGINT UNSIGNED NOT NULL");

        if ($hasForeignKey) {
            Schema::table('bookings', fn (Blueprint $table) => $table->foreign($to)->references('id')->on('users')->restrictOnDelete());
        }
    }

    private function removeObsoleteBookingTypes(): void
    {
        if (! Schema::hasTable('booking_types')) {
            return;
        }

        DB::table('booking_types')->whereNotIn('key', self::APPROVED_TYPE_KEYS)->delete();
    }

    /** @param  Collection<int, string>  $ids */
    private function deleteBookings($ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        foreach (self::BOOKING_ID_CHILD_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'booking_id')) {
                DB::table($table)->whereIn('booking_id', $ids)->delete();
            }
        }

        DB::table('bookings')->whereIn('id', $ids)->delete();
    }
};
