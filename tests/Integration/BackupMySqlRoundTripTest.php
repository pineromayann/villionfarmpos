<?php

use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Backup\BinaryLocator;
use App\Backup\FileArchiver;
use App\Backup\NativeMysqlSnapshot;
use App\Backup\RestoreAccess;
use App\Backup\RestoreService;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Real MySQL Round Trip
|--------------------------------------------------------------------------
|
| Everything else in the suite runs against SQLite, where a restore cannot
| actually happen. This test exercises the real thing: a live MySQL server, the
| real mysqldump binary and the real mysql client, so a genuine dump is taken
| and a genuine import puts it back.
|
| It lives outside tests/Feature because it manages its own database rather than
| using RefreshDatabase, and it skips itself unless the connection under test is
| MySQL. Point it at a throwaway database and let it create everything:
|
|   DB_CONNECTION=mysql DB_DATABASE=vfp_backup_test \
|       vendor/bin/pest --testsuite=Integration
|
| The database is dropped again afterwards, so the host and credentials in
| .env are the only thing it ever needs.
|
*/

const THROWAWAY_DATABASE = 'vfp_backup_test';

/**
 * Locate the MySQL command line tools, falling back to the Windows install
 * path this project is developed against.
 */
function useMySqlBinaries(): void
{
    config([
        'backup.mysql.dump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe'),
        'backup.mysql.client_binary' => env('BACKUP_MYSQL_BINARY', 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe'),
    ]);
}

/**
 * Hide mysqldump so the pure PHP snapshot is used, while leaving the mysql
 * client available because the import still needs it.
 */
function hideMySqlDumpBinary(): void
{
    app()->forgetInstance(BinaryLocator::class);

    app()->singleton(BinaryLocator::class, fn () => new class extends BinaryLocator
    {
        public function find(string $binary): ?string
        {
            return $binary === 'mysqldump' ? null : parent::find($binary);
        }
    });
}

/**
 * Restore the locator the application provider would normally build.
 */
function useRealMySqlBinaries(): void
{
    app()->forgetInstance(BinaryLocator::class);

    app()->singleton(BinaryLocator::class, fn () => (new BinaryLocator)->withPaths(array_filter([
        config('backup.mysql.dump_binary'),
        config('backup.mysql.client_binary'),
    ])));
}

/**
 * Create the throwaway database and migrate it from scratch.
 *
 * Every run starts from an empty schema, so a dump that was missing a table
 * cannot be masked by rows left over from a previous run.
 */
function prepareThrowawayMySqlDatabase(): void
{
    // Connect without a database so it can be created, then point the
    // application at it.
    config(['database.connections.mysql.database' => null]);
    DB::purge('mysql');

    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.THROWAWAY_DATABASE.'`');
    DB::connection('mysql')->statement(
        'CREATE DATABASE `'.THROWAWAY_DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    config(['database.connections.mysql.database' => THROWAWAY_DATABASE]);
    DB::purge('mysql');
    DB::reconnect('mysql');

    Artisan::call('migrate', ['--force' => true]);
}

/**
 * A fingerprint of every business table, used to prove the data came back
 * exactly as it was.
 *
 * @return array<string, int>
 */
function fingerprintBusinessData(): array
{
    $tables = [
        'users', 'customers', 'products', 'sales', 'sale_items', 'refunds',
        'suppliers', 'stock_movements', 'purchase_orders', 'purchase_order_items',
        'consignment_partners', 'consignments', 'consignment_items',
        'consignment_sales', 'consignment_adjustments', 'consignment_settlements',
    ];

    $fingerprint = [];

    foreach ($tables as $table) {
        $fingerprint[$table] = DB::table($table)->count();
    }

    return $fingerprint;
}

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('The restore path only runs against a real MySQL server.');
    }

    useMySqlBinaries();

    if (! is_executable((string) config('backup.mysql.dump_binary'))) {
        $this->markTestSkipped('mysqldump is not available on this machine.');
    }

    prepareThrowawayMySqlDatabase();

    Storage::fake(config('backup.disk', 'local'));

    config([
        'backup.files' => ['storage/framework/testing/backup-fixtures'],
        'backup.exclude' => ['**/skip/**'],
        'backup.work_directory' => storage_path('framework/testing/backup-work'),
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    // Restoring is a database switch now, not a config value.
    app(RestoreAccess::class)->set(true);

    File::deleteDirectory(storage_path('framework/testing/backup-fixtures'));
    File::ensureDirectoryExists(storage_path('framework/testing/backup-fixtures/dbjsons'));
});

afterEach(function () {
    if (DB::getDriverName() === 'mysql') {
        config(['database.connections.mysql.database' => null]);
        DB::purge('mysql');
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.THROWAWAY_DATABASE.'`');
        DB::purge('mysql');
    }
});

test('a real mysqldump snapshot restores every business table exactly', function () {
    $customer = Customer::factory()->create(['name' => 'Akoto Farms Ltd']);
    $supplier = Supplier::factory()->create(['name' => 'Asante Agro Supplies']);
    $product = Product::factory()->create([
        'name' => 'Apex 25EC',
        'batch_number' => 'BATCH-'.strtoupper(bin2hex(random_bytes(3))),
        'stock' => 320.00,
    ]);

    $sale = Sale::factory()->create(['customer_id' => $customer->id, 'total' => 291.00]);
    $saleItem = SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2]);
    StockMovement::factory()->outgoing()->create(['product_id' => $product->id, 'quantity' => -2]);

    $order = PurchaseOrder::factory()->received()->create(['supplier_id' => $supplier->id, 'total' => 1200.00]);
    PurchaseOrderItem::factory()->create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 24]);

    $partner = ConsignmentPartner::factory()->create(['name' => 'Mensah Cooperative']);
    $consignment = Consignment::factory()->create([
        'partner_id' => $partner->id,
        'received_at' => now()->toDateString(),
    ]);
    ConsignmentItem::factory()->create([
        'consignment_id' => $consignment->id,
        'product_id' => $product->id,
        'quantity' => 40,
    ]);
    ConsignmentSale::factory()->create([
        'partner_id' => $partner->id,
        'sale_id' => $sale->id,
        'sale_item_id' => $saleItem->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    $expected = fingerprintBusinessData();
    $expectedStock = (float) $product->fresh()->stock;
    $expectedBatch = $product->fresh()->batch_number;

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->database_name)->toBe('vfp_backup_test')
        ->and($backup->snapshot['driver'])->toBe('mysqldump')
        ->and($backup->snapshot['tables'])->toBeGreaterThan(10)
        // mysqldump cannot report a row count, and a zero here would read as
        // "this backup is empty".
        ->and($backup->snapshot['rows'])->toBeNull()
        ->and($backup->size)->toBeGreaterThan(0)
        ->and($backup->checksum)->toBe(hash_file('sha256', Storage::disk($backup->disk)->path($backup->path)));

    // The dump is a real gzipped mysqldump stream containing the schema.
    $dump = (string) gzdecode((string) Storage::disk($backup->disk)->get($backup->path));

    expect($dump)->toContain('CREATE TABLE `sales`')
        ->and($dump)->toContain('CREATE TABLE `consignment_sales`')
        ->and($dump)->not->toContain('CREATE TABLE `failed_jobs`');

    // Everything changes after the dump was taken.
    DB::table('sale_items')->delete();
    DB::table('sales')->delete();
    DB::table('stock_movements')->delete();
    DB::table('purchase_order_items')->delete();
    DB::table('consignment_sales')->delete();
    DB::table('customers')->delete();
    DB::table('suppliers')->delete();
    DB::table('consignment_partners')->delete();
    DB::table('consignment_items')->delete();
    DB::table('consignments')->delete();
    DB::table('products')->update(['stock' => 0, 'batch_number' => 'WIPED']);
    DB::table('purchase_orders')->delete();

    expect(DB::table('sales')->count())->toBe(0)
        ->and((float) DB::table('products')->first()->stock)->toBe(0.0);

    // The import runs the dump back through the mysql client.
    $result = app(RestoreService::class)->restore($backup->fresh());

    expect($result['backup']->id)->toBe($backup->id);

    expect(fingerprintBusinessData())->toBe($expected)
        ->and((float) DB::table('products')->first()->stock)->toBe($expectedStock)
        ->and(DB::table('products')->first()->batch_number)->toBe($expectedBatch)
        ->and(DB::table('customers')->first()->name)->toBe('Akoto Farms Ltd')
        ->and(DB::table('suppliers')->first()->name)->toBe('Asante Agro Supplies')
        ->and(DB::table('consignment_partners')->first()->name)->toBe('Mensah Cooperative')
        ->and(DB::table('purchase_orders')->first()->status)->toBe('received');

    // No backup row is left claiming to be in progress.
    expect(DB::table('backups')->whereIn('status', ['pending', 'running'])->count())->toBe(0);
});

test('a real full backup restores the database and the archived files', function () {
    $fixture = base_path('storage/framework/testing/backup-fixtures/dbjsons/settings.json');

    writeBackupFixture('dbjsons/settings.json', '{"currency":"GHS","shop":"Kumasi"}');
    writeBackupFixture('public/uploads/logo.png', 'fake-png-bytes');

    $product = Product::factory()->create(['stock' => 77.00, 'batch_number' => 'FULL-001']);
    $expectedStock = (float) $product->fresh()->stock;

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_FULL));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and(file_get_contents($fixture))->toBe('{"currency":"GHS","shop":"Kumasi"}');

    // Wipe both the database and the archived files, the way a bad deploy or a
    // rogue edit would.
    DB::table('products')->delete();
    File::put($fixture, '{"currency":"EUR","shop":"Tamale"}');

    expect(file_get_contents($fixture))->toBe('{"currency":"EUR","shop":"Tamale"}');

    app(RestoreService::class)->restore($backup->fresh());

    expect(DB::table('products')->count())->toBe(1)
        ->and((float) DB::table('products')->first()->stock)->toBe($expectedStock)
        ->and(DB::table('products')->first()->batch_number)->toBe('FULL-001')
        ->and(file_get_contents($fixture))->toBe('{"currency":"GHS","shop":"Kumasi"}');
});

test('a real restore is refused when the artifact is corrupt', function () {
    $product = Product::factory()->create();

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE));

    // Corrupt the artifact behind the service's back, keeping the size similar.
    Storage::disk($backup->disk)->put($backup->path, gzencode('SELECT 1;'));

    expect(fn () => app(RestoreService::class)->restore($backup->fresh()))
        ->toThrow(BackupException::class, 'is corrupt');

    expect(DB::table('products')->count())->toBe(1);
});

test('the pure PHP snapshot round trips when mysqldump is unavailable', function () {
    $customer = Customer::factory()->create(['name' => 'Fallback Farms']);
    $product = Product::factory()->create(['stock' => 412.50, 'batch_number' => 'FALLBACK-1']);
    $sale = Sale::factory()->create(['customer_id' => $customer->id]);
    $saleItem = SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 3]);
    $partner = ConsignmentPartner::factory()->create(['name' => 'Fallback Cooperative']);
    $consignment = Consignment::factory()->create([
        'partner_id' => $partner->id,
        'received_at' => now()->toDateString(),
    ]);
    ConsignmentItem::factory()->create(['consignment_id' => $consignment->id, 'product_id' => $product->id, 'quantity' => 9]);
    ConsignmentSale::factory()->create([
        'partner_id' => $partner->id,
        'sale_id' => $sale->id,
        'sale_item_id' => $saleItem->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $expected = fingerprintBusinessData();

    // Pretend the server has no mysqldump at all, which is the situation on
    // most shared hosting. The mysql client is still needed for the import.
    hideMySqlDumpBinary();

    expect(app(BackupService::class)->snapshot())->toBeInstanceOf(NativeMysqlSnapshot::class);

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->snapshot['driver'])->toBe('mysql-native')
        ->and($backup->snapshot['tables'])->toBeGreaterThan(10)
        ->and($backup->snapshot['rows'])->toBeGreaterThan(0)
        ->and($backup->size)->toBeGreaterThan(0)
        ->and($backup->checksum)->toBe(hash_file('sha256', Storage::disk($backup->disk)->path($backup->path)));

    DB::table('sale_items')->delete();
    DB::table('sales')->delete();
    DB::table('consignment_sales')->delete();
    DB::table('consignment_items')->delete();
    DB::table('consignments')->delete();
    DB::table('consignment_partners')->delete();
    DB::table('customers')->delete();
    DB::table('products')->update(['stock' => 0, 'batch_number' => 'WIPED']);

    useRealMySqlBinaries();

    // The import still goes through the mysql client, because only the dump
    // side has a PHP fallback.
    app(RestoreService::class)->restore($backup->fresh());

    expect(fingerprintBusinessData())->toBe($expected)
        ->and((float) DB::table('products')->first()->stock)->toBe(412.5)
        ->and(DB::table('products')->first()->batch_number)->toBe('FALLBACK-1')
        ->and(DB::table('customers')->first()->name)->toBe('Fallback Farms')
        ->and(DB::table('consignment_partners')->first()->name)->toBe('Fallback Cooperative');
});

test('a configured absolute path to a binary is honoured', function () {
    $locator = (new BinaryLocator)->withPaths([
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
    ]);

    expect($locator->find('mysqldump'))->toBe('C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe')
        ->and($locator->find('definitely-not-a-real-binary'))->toBeNull();
});

test('a successful restore takes the site out of maintenance mode', function () {
    $admin = User::factory()->administrator()->create(['name' => 'Maintenance Admin']);
    $product = Product::factory()->create(['name' => 'Apex 25EC', 'batch_number' => 'BATCH-MAINT', 'stock' => 120.00]);

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE, $admin, 'daily'));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED);

    config(['backup.restore.maintenance_mode' => true]);

    $manager = app()->maintenanceMode();

    try {
        $result = app(RestoreService::class)->restore($backup, $admin);

        expect($result['backup']->id)->toBe($backup->id)
            ->and($manager->active())->toBeFalse()
            ->and((float) $product->fresh()->stock)->toBe(120.0);

        // A real request must no longer be turned away with a 503.
        expect($this->get('/')->getStatusCode())->not->toBe(503);
    } finally {
        $manager->deactivate();
    }
});

test('a cleanup failure after a successful restore still brings the site back', function () {
    $admin = User::factory()->administrator()->create(['name' => 'Cleanup Admin']);

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE, $admin, 'daily'));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED);

    config(['backup.restore.maintenance_mode' => true]);

    $manager = app()->maintenanceMode();

    // Deleting the temp directory is the last thing that happens, and it must
    // never be able to strand a successfully restored site behind a 503.
    $restorer = new class(app(BackupService::class), app(FileArchiver::class), app(BinaryLocator::class), app(RestoreAccess::class)) extends RestoreService
    {
        protected function deleteDirectory(string $directory): void
        {
            throw new RuntimeException('temp cleanup failed');
        }
    };

    try {
        expect(fn () => $restorer->restore($backup, $admin))
            ->toThrow(RuntimeException::class, 'temp cleanup failed');

        expect($manager->active())->toBeFalse()
            ->and($this->get('/')->getStatusCode())->not->toBe(503);
    } finally {
        $manager->deactivate();
    }
});

test('an administrator can restore from the backups screen and the data comes back', function () {
    $admin = User::factory()->administrator()->create(['name' => 'Restore Admin']);
    $customer = Customer::factory()->create(['name' => 'Before The Restore']);
    $product = Product::factory()->create([
        'name' => 'Apex 25EC',
        'batch_number' => 'BATCH-BEFORE',
        'stock' => 500.00,
    ]);
    $supplier = Supplier::factory()->create(['name' => 'Supplier Before']);
    $sale = Sale::factory()->create(['customer_id' => $customer->id, 'total' => 400.00]);
    SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 4]);
    StockMovement::factory()->outgoing()->create(['product_id' => $product->id, 'quantity' => -4]);

    $expected = fingerprintBusinessData();

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE, $admin, 'daily'));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED);

    // Destroy the evidence the way a bad day at the till would: the records are
    // gone and the stock is wrong. Children are removed before their parents or
    // the foreign keys refuse the delete.
    StockMovement::query()->delete();
    SaleItem::query()->delete();
    Sale::query()->delete();
    Product::query()->delete();
    Customer::query()->delete();
    Supplier::query()->delete();
    DB::purge('mysql');

    expect(fingerprintBusinessData())->not->toBe($expected);

    $response = $this->actingAs($admin)->post(route('backups.restore', $backup), [
        'confirmation' => $backup->filename,
    ]);

    $response->assertRedirect(route('backups.show', $backup));
    $response->assertSessionHas('success');

    DB::purge('mysql');

    expect(fingerprintBusinessData())->toBe($expected);

    expect(Product::query()->find($product->id))
        ->not->toBeNull()
        ->and(Product::query()->find($product->id)->batch_number)->toBe('BATCH-BEFORE')
        ->and((float) Product::query()->find($product->id)->stock)->toEqual(500.00);

    expect(Customer::query()->where('name', 'Before The Restore')->exists())->toBeTrue()
        ->and(Supplier::query()->where('name', 'Supplier Before')->exists())->toBeTrue()
        ->and(Sale::query()->count())->toBe(1);

    // The restore itself is recorded, and the backup that was restored from
    // stays on file so the action can be traced later.
    $this->assertDatabaseHas('activity_logs', [
        'action' => ActivityLog::ACTION_RESTORE_COMPLETED,
        'subject_type' => Backup::class,
        'subject_id' => $backup->id,
        'user_id' => $admin->id,
    ]);

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeTrue();
});

test('the restore is refused through the web when the filename is not typed exactly', function () {
    $admin = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Apex 25EC', 'stock' => 100.00]);

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE, $admin));

    $expected = fingerprintBusinessData();

    $this->actingAs($admin)
        ->post(route('backups.restore', $backup), ['confirmation' => 'not-the-filename'])
        ->assertSessionHasErrors('confirmation');

    DB::purge('mysql');

    expect(fingerprintBusinessData())->toBe($expected);
});

test('a cashier cannot restore a backup through the web', function () {
    $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
    $product = Product::factory()->create(['name' => 'Apex 25EC', 'stock' => 100.00]);

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE));

    $expected = fingerprintBusinessData();

    $this->actingAs($cashier)
        ->post(route('backups.restore', $backup), ['confirmation' => $backup->filename])
        ->assertForbidden();

    DB::purge('mysql');

    expect(fingerprintBusinessData())->toBe($expected);
});
