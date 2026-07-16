<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use App\Services\ContactImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins ContactImportService::readCsv path-resolution.
 *
 * Real-world failure that motivated this test: production worker
 * processed queued ProcessContactImport jobs in milliseconds with FAIL
 * status. Stacktrace pointed at:
 *   fopen('imports/abc.csv'): Failed to open stream: No such file...
 *
 * Root cause: the controller calls $request->file()->store('imports'),
 * which returns a disk KEY ("imports/abc.csv") not an absolute path.
 * Bare fopen() resolves relative paths against process CWD, which for
 * the supervised worker is the project root — where no 'imports' dir
 * lives. Files actually sit at storage/app/imports/.
 *
 * Fix: readCsv and readXlsx now call absoluteCsvPath() to convert
 * disk keys to storage/app/<key>. This test pins that an import
 * dispatched the same way the controller dispatches it (via
 * UploadedFile->store('imports')) successfully reads through to
 * the parsed rows.
 */
class ContactImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_resolves_disk_key_to_storage_app_path(): void
    {
        // Mirrors exactly what ContactController::importProcess does:
        //   $path = $request->file('file')->store('imports');
        // Then dispatches ProcessContactImport with that $path string,
        // which the job hands to ContactImportService::importFromFile.
        //
        // Before the fix: fopen($path) looked for './imports/abc.csv'
        // in the CWD and failed.
        // After the fix: storage_path('app/'.$path) resolves correctly.
        Storage::fake('local');

        $admin = User::factory()->create(['is_active' => true]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Targets']);

        $csv = "phone,name\n+2348012345678,Alice\n+2348087654321,Bob\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        // Same call pattern as the controller — returns a disk key like
        // "imports/<random>.csv", NOT an absolute path.
        $diskKey = $file->store('imports');

        $service = new ContactImportService();
        $result = $service->importFromFile(
            $diskKey,
            $group->id,
            ['phone' => 'phone', 'name' => 'name'],
            $admin->id,
        );

        // Before the fix: 0 imported, fopen error in the log.
        // After the fix: both rows parse and persist.
        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['invalid']);
    }

    public function test_reimporting_a_soft_deleted_contact_revives_it_instead_of_crashing(): void
    {
        // Production-Audit blocker B1: a plain updateOrCreate on a soft-deleted
        // (user_id, phone) used to throw a QueryException on the unversioned
        // unique index, killing the import mid-batch. It must revive + update.
        Storage::fake('local');

        $admin = User::factory()->create(['is_active' => true]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Targets']);

        $deleted = Contact::create([
            'user_id' => $admin->id,
            'phone' => '2348012345678',
            'name' => 'Before Delete',
        ]);
        $deleted->delete();

        $csv = "phone,name\n+2348012345678,After Reimport\n";
        $diskKey = UploadedFile::fake()->createWithContent('contacts.csv', $csv)->store('imports');

        $result = (new ContactImportService())->importFromFile(
            $diskKey,
            $group->id,
            ['phone' => 'phone', 'name' => 'name'],
            $admin->id,
        );

        // Row revived + updated, not duplicated, and the batch completed.
        $this->assertSame(1, Contact::withTrashed()->where('phone', '2348012345678')->count());
        $revived = Contact::where('phone', '2348012345678')->first();
        $this->assertNotNull($revived);
        $this->assertSame($deleted->id, $revived->id);
        $this->assertSame('After Reimport', $revived->name);
        $this->assertTrue($group->contacts()->where('contact_id', $revived->id)->exists());
    }

    public function test_import_still_accepts_absolute_path_for_backward_compat(): void
    {
        // Existing callers (and direct CLI usage) may already be passing
        // an absolute filesystem path. The path-resolution helper passes
        // those through unchanged — so this branch must also work end-to-end.
        Storage::fake('local');

        $admin = User::factory()->create(['is_active' => true]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Targets']);

        // Write a real CSV to a real absolute path.
        $absolute = storage_path('app/imports/'.uniqid('absolute_').'.csv');
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, "phone,name\n+2348011112222,Direct\n");

        try {
            $service = new ContactImportService();
            $result = $service->importFromFile(
                $absolute,
                $group->id,
                ['phone' => 'phone', 'name' => 'name'],
                $admin->id,
            );

            $this->assertSame(1, $result['imported']);
        } finally {
            @unlink($absolute);
        }
    }

    public function test_reimport_with_blank_name_preserves_existing_name(): void
    {
        // Data-loss regression: re-importing a phone-only list (blank name
        // cell) must NOT overwrite a contact's previously stored name.
        Storage::fake('local');

        $admin = User::factory()->create(['is_active' => true]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Targets']);
        $service = new ContactImportService();
        $map = ['phone' => 'phone', 'name' => 'name'];

        $key1 = UploadedFile::fake()->createWithContent('c1.csv', "phone,name\n2348012345678,Alice\n")->store('imports');
        $service->importFromFile($key1, $group->id, $map, $admin->id);
        $this->assertSame('Alice', Contact::where('phone', '2348012345678')->firstOrFail()->name);

        // Second import: same phone, blank name cell.
        $key2 = UploadedFile::fake()->createWithContent('c2.csv', "phone,name\n2348012345678,\n")->store('imports');
        $service->importFromFile($key2, $group->id, $map, $admin->id);

        $this->assertSame('Alice', Contact::where('phone', '2348012345678')->firstOrFail()->name);
    }
}
