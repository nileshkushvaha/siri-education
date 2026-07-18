<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23J boundary: the instructor homework review workflow
 * is a strict extension of the existing app/Homework/* domain — no
 * parallel InstructorHomework/InstructorSubmission/HomeworkReview
 * entities, no direct DB writes from Livewire, ownership enforced by
 * policy (not just UI hiding), and bounded/aggregate queries.
 */
final class Phase23JArchitectureTest extends TestCase
{
    public function test_no_duplicate_homework_domain_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/InstructorHomework.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorSubmission.php'));
        $this->assertFileDoesNotExist(app_path('Models/HomeworkReview.php'));
        $this->assertDirectoryDoesNotExist(app_path('Services/InstructorHomework'));
        $this->assertDirectoryDoesNotExist(app_path('InstructorHomework'));

        $matches = [];
        foreach ($this->phpFilesUnder(app_path('Models')) as $file) {
            if (str_contains(basename($file), 'Homework')) {
                $matches[] = $file;
            }
        }

        $this->assertCount(1, $matches, 'Exactly one Homework model must exist (App\\Models\\HomeworkAssignment).');
    }

    public function test_instructor_homework_service_extends_the_existing_contracts_not_a_new_one(): void
    {
        $serviceInterface = file_get_contents(app_path('Homework/Contracts/HomeworkServiceInterface.php'));
        $this->assertIsString($serviceInterface);
        $this->assertStringContainsString('paginatedForTeacher', $serviceInterface);
        $this->assertStringContainsString('function review(', $serviceInterface);

        $repositoryInterface = file_get_contents(app_path('Homework/Contracts/HomeworkRepositoryInterface.php'));
        $this->assertIsString($repositoryInterface);
        $this->assertStringContainsString('paginatedForTeacher', $repositoryInterface);

        // Only one concrete implementation of each contract exists.
        $this->assertFileExists(app_path('Homework/Services/HomeworkService.php'));
        $this->assertFileExists(app_path('Homework/Repositories/HomeworkRepository.php'));

        $serviceMatches = [];
        foreach ($this->phpFilesUnder(app_path('Homework/Services')) as $file) {
            $serviceMatches[] = $file;
        }
        $this->assertCount(1, $serviceMatches);
    }

    public function test_livewire_component_never_writes_to_the_database_directly(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/HomeworkList.php'));
        $this->assertIsString($component);

        $this->assertStringNotContainsString('->save()', $component);
        $this->assertStringNotContainsString('::create(', $component);
        $this->assertStringNotContainsString('->update(', $component);
        $this->assertStringContainsString('$this->homework->review(', $component);
    }

    public function test_review_write_goes_through_a_transactional_service_method(): void
    {
        $service = file_get_contents(app_path('Homework/Services/HomeworkService.php'));
        $this->assertIsString($service);
        $this->assertStringContainsString('DB::transaction', $service);
        $this->assertStringContainsString('reviewAction', $service);
    }

    public function test_ownership_is_enforced_by_policy_not_only_by_the_view(): void
    {
        $policy = file_get_contents(app_path('Policies/HomeworkAssignmentPolicy.php'));
        $this->assertIsString($policy);
        $this->assertStringContainsString('function review(User $user, HomeworkAssignment $assignment): bool', $policy);
        $this->assertStringContainsString('$assignment->teacher_id', $policy);

        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/HomeworkList.php'));
        $this->assertIsString($component);
        $this->assertStringContainsString("authorize('review'", $component);
    }

    public function test_repository_pending_review_count_is_a_bounded_aggregate_not_a_materialized_collection(): void
    {
        $repository = file_get_contents(app_path('Homework/Repositories/HomeworkRepository.php'));
        $this->assertIsString($repository);

        $this->assertStringContainsString('function pendingReviewCountForTeacher', $repository);
        $this->assertStringContainsString('->count();', $repository);

        $this->assertStringContainsString('function recentlyGradedForTeacher', $repository);
        $this->assertStringContainsString('->limit($limit)', $repository);

        $this->assertStringContainsString('function paginatedForTeacher', $repository);
        $this->assertStringContainsString('->paginate($perPage)', $repository);
    }

    public function test_dashboard_widget_reuses_the_existing_bounded_count_not_a_second_query(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);
        $this->assertStringContainsString("'pending_reviews' => (clone \$submittedHomework)->count()", $service);
    }

    public function test_reuses_the_existing_homework_status_enum(): void
    {
        $action = file_get_contents(app_path('Homework/Actions/ReviewHomeworkAction.php'));
        $this->assertIsString($action);
        $this->assertStringContainsString('HomeworkStatus::Graded', $action);
        $this->assertStringContainsString('HomeworkStatus::Submitted', $action);

        // No second status enum was introduced for the instructor workflow.
        $this->assertFileDoesNotExist(app_path('Homework/Enums/HomeworkReviewStatus.php'));
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
