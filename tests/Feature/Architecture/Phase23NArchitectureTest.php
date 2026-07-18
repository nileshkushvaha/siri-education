<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23N boundary: the instructor student roster is a
 * pure read layer derived from Lesson — no new relationship table, no
 * duplicate student domain, no direct DB writes from Livewire, and no
 * payment/private-feedback fields ever selected or rendered.
 */
final class Phase23NArchitectureTest extends TestCase
{
    public function test_no_duplicate_student_relationship_domain_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/InstructorStudent.php'));
        $this->assertFileDoesNotExist(app_path('Models/StudentRelationship.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorCRM.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorCRMService.php'));

        // No migration creates a new relationship table — the roster is
        // Lesson-derived. (instructor_student_feedback is Phase 17Q's
        // pre-existing private-feedback table, not a relationship table.)
        $migrations = glob(database_path('migrations/*.php'));
        $newRelationshipMigrations = array_filter(
            $migrations,
            fn (string $file): bool => (bool) preg_match(
                '/create_(student_relationships|instructor_students)_table/',
                strtolower(basename($file)),
            ),
        );
        $this->assertCount(0, $newRelationshipMigrations);
    }

    public function test_roster_service_derives_the_relationship_from_lesson_not_a_new_table(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorStudentService.php'));
        $this->assertIsString($service);

        $this->assertStringContainsString('Lesson::query()', $service);
        $this->assertStringContainsString('->forInstructor(', $service);
        $this->assertStringNotContainsString('InstructorStudent::', $service);
        $this->assertStringNotContainsString('StudentRelationship::', $service);
    }

    public function test_livewire_components_never_write_to_the_database(): void
    {
        $list = file_get_contents(app_path('Livewire/Frontend/Instructor/StudentList.php'));
        $detail = file_get_contents(app_path('Livewire/Frontend/Instructor/StudentDetail.php'));
        $this->assertIsString($list);
        $this->assertIsString($detail);

        foreach ([$list, $detail] as $component) {
            $this->assertStringNotContainsString('->save()', $component);
            $this->assertStringNotContainsString('::create(', $component);
            $this->assertStringNotContainsString('->update(', $component);
            $this->assertStringNotContainsString('->delete(', $component);
        }

        // Both are read-only: render() (+ boot()/mount() for DI/ownership
        // wiring, never a mutation) are the only public methods.
        $this->assertSame(2, preg_match_all('/public function \w+\(/', $list));
        $this->assertSame(3, preg_match_all('/public function \w+\(/', $detail));
    }

    public function test_no_payment_private_feedback_or_contact_fields_are_selected_or_rendered(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorStudentService.php'));
        $dto = file_get_contents(app_path('DTOs/Instructor/InstructorStudentSummaryData.php'));
        $listView = file_get_contents(resource_path('views/livewire/frontend/instructor/student-list.blade.php'));
        $detailView = file_get_contents(resource_path('views/livewire/frontend/instructor/student-detail.blade.php'));

        foreach ([$service, $dto, $listView, $detailView] as $source) {
            $this->assertIsString($source);
            $this->assertStringNotContainsString('email', strtolower($source));
            $this->assertStringNotContainsString('phone', strtolower($source));
            $this->assertStringNotContainsString('wallet', strtolower($source));
            $this->assertStringNotContainsString('payment', strtolower($source));
            $this->assertStringNotContainsString('InstructorStudentFeedback', $source);
        }
    }

    public function test_roster_query_is_paginated_and_bounded(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorStudentService.php'));
        $this->assertIsString($service);

        $method = substr($service, (int) strpos($service, 'function paginatedForInstructor'));
        $method = substr($method, 0, (int) strpos($method, "\n    }"));

        $this->assertStringContainsString('->paginate($perPage)', $method);
        $this->assertStringNotContainsString('->get()', $method);
    }

    public function test_hydration_never_queries_per_row(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorStudentService.php'));
        $this->assertIsString($service);

        $method = substr($service, (int) strpos($service, 'private function hydrate'));

        // Both lookups use whereIn — a single bounded query for the whole
        // page, never one issued inside the ->map() callback below them.
        $this->assertStringContainsString('whereIn(\'id\', $studentIds)', $method);
        $this->assertStringContainsString('whereIn(\'student_user_id\', $studentIds)', $method);
    }

    public function test_navigation_adds_students_without_removing_existing_teach_items(): void
    {
        $menu = file_get_contents(app_path('Services/Account/AccountMenuService.php'));
        $this->assertIsString($menu);

        $this->assertStringContainsString("'Students', 'dashboard.instructor.students'", $menu);
        $this->assertStringContainsString("'My Lessons', 'dashboard.instructor.lessons'", $menu);
        $this->assertStringContainsString("'Homework', 'dashboard.instructor.homework'", $menu);
        $this->assertStringContainsString("'Learning Plans', 'dashboard.instructor.learning-plans'", $menu);
    }

    public function test_ownership_enforced_by_scoped_lesson_lookup_on_detail_page(): void
    {
        $detail = file_get_contents(app_path('Livewire/Frontend/Instructor/StudentDetail.php'));
        $this->assertIsString($detail);

        $this->assertStringContainsString('hasRelationship((int) auth()->id()', $detail);
        $this->assertStringContainsString('abort_unless(', $detail);
    }
}
