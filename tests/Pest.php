<?php

use App\Backup\RestoreAccess;
use App\Models\Backup;
use App\Models\ConsignmentPartner;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Integration tests own their database instead of using RefreshDatabase, so
// they can run migrations, wipe the schema and restore over it themselves.
pest()->extend(TestCase::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function uomUnit(string $abbreviation): Unit
{
    return Unit::where('abbreviation', $abbreviation)->firstOrFail();
}

function uomProductWithDozen(array $attributes = []): Product
{
    $product = Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 48,
        'price' => 5,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        ...$attributes,
    ]);

    $product->sellingUnits()->create([
        'unit_id' => uomUnit('dz')->id,
        'conversion_to_base' => 12,
        'is_base' => false,
    ]);

    return $product;
}

function consignmentPartner(): ConsignmentPartner
{
    return ConsignmentPartner::factory()->create();
}

function consignmentProduct(array $attributes = []): Product
{
    return Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 48,
        'price' => 5,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
        ...$attributes,
    ]);
}

/**
 * Point the backup system at throwaway locations.
 *
 * The destination disk is faked, and the archiver is pointed at a fixture
 * directory inside the project so it walks real files on the real filesystem,
 * which is what the glob and path handling has to get right.
 */
function useBackupTestingDisk(): void
{
    Storage::fake(config('backup.disk', 'local'));

    config([
        'backup.files' => ['storage/framework/testing/backup-fixtures'],
        'backup.exclude' => ['**/skip/**', '**/*.log'],
        'backup.work_directory' => storage_path('framework/testing/backup-work'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/backup-fixtures'));
    File::deleteDirectory(storage_path('framework/testing/backup-work'));
    File::ensureDirectoryExists(storage_path('framework/testing/backup-fixtures/dbjsons'));
    File::ensureDirectoryExists(storage_path('framework/testing/backup-fixtures/public/uploads'));
    File::ensureDirectoryExists(storage_path('framework/testing/backup-fixtures/skip'));
}

function writeBackupFixture(string $relative, string $contents): string
{
    $path = storage_path('framework/testing/backup-fixtures').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

function useRestorableBackups(): void
{
    app(RestoreAccess::class)->set(true);
}

function backupDisk(): string
{
    return (string) config('backup.disk', 'local');
}

/**
 * Copy a stored backup artifact to a temporary path and list its zip entries.
 *
 * @return array<int, string>
 */
function zipEntriesOf(Backup $backup): array
{
    $path = tempnam(sys_get_temp_dir(), 'vfpz-');
    file_put_contents($path, Storage::disk($backup->disk)->get($backup->path));

    $zip = new ZipArchive;

    if ($zip->open($path) !== true) {
        return [];
    }

    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entries[] = (string) $zip->getNameIndex($index);
    }

    $zip->close();
    unlink($path);

    return $entries;
}

function decompress(string $path): string
{
    $contents = gzdecode((string) file_get_contents($path));

    expect($contents)->toBeString();

    return $contents;
}
