<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Jobs\ProcessContactImport;
use App\Models\ContactGroup;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins the CSV upload → job dispatch contract.
 *
 * Real-world failure that triggered this test: ContactController dispatched
 * ProcessContactImport with arguments in the WRONG ORDER for the constructor.
 * PHP 8's typed-promoted-properties threw a synchronous TypeError, the
 * response became a 500, and the user saw a generic error page when trying
 * to upload 1059 contacts. Local manual-paste imports skip the queue path
 * entirely so the bug was invisible during dev.
 *
 * The test below would have caught the regression before it shipped:
 * Queue::assertPushed with a callback that inspects the constructor's
 * typed properties — if anything is the wrong type, the assertion fails.
 */
class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_csv_upload_dispatches_process_contact_import_with_correct_argument_order(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Import target']);

        $csv = "phone,name\n+2348012345678,Alice\n+2348087654321,Bob\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($admin)
            ->post(route('contacts.importProcess'), [
                'file' => $file,
                'group_id' => $group->id,
                'column_map' => ['phone' => 'phone', 'name' => 'name'],
            ])
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessContactImport::class, function (ProcessContactImport $job) use ($group, $admin) {
            // These assertions individually verify each typed property
            // received a value of the right shape — a TypeError on
            // construction would fail BEFORE this callback ever ran, so
            // simply reaching the body and seeing the right values is
            // the proof that argument order is correct.
            return is_string($job->filePath)
                && $job->filePath !== ''
                && $job->groupId === $group->id
                && is_array($job->columnMap)
                && $job->columnMap['phone'] === 'phone'
                && $job->userId === $admin->id;
        });
    }

    public function test_csv_upload_without_column_map_still_dispatches(): void
    {
        // Belt-and-suspenders: the validator marks column_map as nullable,
        // so the controller must default to [] (not pass null into the
        // job's `array $columnMap` typed property).
        Queue::fake();

        $admin = $this->makeAdmin();
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Import target']);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', "+2348011111111\n");

        $this->actingAs($admin)
            ->post(route('contacts.importProcess'), [
                'file' => $file,
                'group_id' => $group->id,
                // no column_map at all
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessContactImport::class, fn ($job) => $job->columnMap === []);
    }

    public function test_admin_can_download_csv_template(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('contacts.importTemplate'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=blastiq-contacts-template.csv');

        $body = $response->streamedContent();
        // BOM so Excel opens UTF-8 cleanly.
        $this->assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        // The four columns the import service consumes.
        $this->assertStringContainsString('phone,name,custom_field_1,custom_field_2', $body);
        // At least one realistic sample row so operators have a visual pattern.
        $this->assertStringContainsString('+2348012345678', $body);
    }

    public function test_user_without_import_permission_cannot_download_template(): void
    {
        // Same gate as importForm/importProcess. A user without the
        // contacts.import permission should not be able to probe the
        // column spec via the template route either.
        $noRole = User::factory()->create(['is_active' => true]);

        $this->actingAs($noRole)
            ->get(route('contacts.importTemplate'))
            ->assertForbidden();
    }

    public function test_csv_upload_requires_authentication(): void
    {
        // Without auth, the controller can't read auth()->id() for userId,
        // which would also have caused a TypeError on the job constructor.
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('contacts.csv', 'x');

        $this->post(route('contacts.importProcess'), [
            'file' => $file,
            'group_id' => 1,
        ])->assertRedirect(route('login'));
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
