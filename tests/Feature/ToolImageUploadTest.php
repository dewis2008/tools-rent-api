<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Categories\Models\Category;
use Modules\ToolImages\Models\PendingToolImageFileDeletion;
use Modules\ToolImages\Models\ToolImage;
use Modules\ToolImages\Services\ToolImageService;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use RuntimeException;
use Tests\TestCase;

class ToolImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_upload_tool_image_for_own_tool(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);

        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->post('/api/v1/tool-images', [
                'tool_id' => $tool->id,
                'image' => UploadedFile::fake()->image('drill.jpg', 640, 480),
                'is_main' => true,
                'sort_order' => 1,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('tool_id', $tool->id)
            ->assertJsonPath('is_main', true)
            ->assertJsonPath('sort_order', 1);

        $toolImage = ToolImage::query()->firstOrFail();

        $this->assertStringStartsWith("tool-images/{$tool->id}/", $toolImage->image_path);
        Storage::disk('public')->assertExists($toolImage->image_path);
    }

    public function test_tool_image_upload_rejects_non_images_and_client_supplied_paths(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);

        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->post('/api/v1/tool-images', [
                'tool_id' => $tool->id,
                'image' => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
                'image_path' => 'tool-images/trusted-client-path.jpg',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image', 'image_path']);

        $this->assertDatabaseCount('tool_images', 0);
    }

    public function test_tool_image_requests_reject_malformed_tool_ids_with_validation_errors(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => "tool-images/{$tool->id}/existing.jpg",
        ]);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/tool-images', [
                'tool_id' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tool_id');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/tool-images/{$toolImage->id}", [
                'tool_id' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tool_id');
    }

    public function test_updating_tool_image_replaces_stored_file(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $oldPath = "tool-images/{$tool->id}/old.jpg";

        Storage::disk('public')->put($oldPath, 'old image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $oldPath,
        ]);

        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patch("/api/v1/tool-images/{$toolImage->id}", [
                'image' => UploadedFile::fake()->image('replacement.png', 640, 480),
                'sort_order' => 2,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('sort_order', 2);

        $toolImage->refresh();

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($toolImage->image_path);
        $this->assertNotSame($oldPath, $toolImage->image_path);
    }

    public function test_deleting_tool_image_removes_stored_file(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/delete-me.jpg";

        Storage::disk('public')->put($path, 'stored image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);

        $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->delete("/api/v1/tool-images/{$toolImage->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tool_images', ['id' => $toolImage->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_tool_image_file_is_not_deleted_when_the_database_transaction_rolls_back(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/rollback.jpg";

        Storage::disk('public')->put($path, 'stored image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);

        try {
            DB::transaction(function () use ($tool): void {
                $tool->delete();

                throw new RuntimeException('Rollback the tool deletion.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback the tool deletion.', $exception->getMessage());
        }

        $this->assertDatabaseHas('tools', [
            'id' => $tool->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('tool_images', ['id' => $toolImage->id]);
        $this->assertDatabaseCount('pending_tool_image_file_deletions', 0);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacement_file_is_removed_when_an_outer_transaction_rolls_back(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $oldPath = "tool-images/{$tool->id}/original.jpg";
        $newPath = null;

        Storage::disk('public')->put($oldPath, 'stored image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $oldPath,
        ]);

        try {
            DB::transaction(function () use ($toolImage, &$newPath): void {
                $updatedImage = app(ToolImageService::class)->update($toolImage, [
                    'image' => UploadedFile::fake()->image('replacement.jpg', 640, 480),
                ]);
                $newPath = $updatedImage->image_path;

                throw new RuntimeException('Rollback the image replacement.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback the image replacement.', $exception->getMessage());
        }

        $this->assertSame($oldPath, $toolImage->refresh()->image_path);
        $this->assertDatabaseCount('pending_tool_image_file_deletions', 0);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_failed_file_deletion_is_retained_for_retry(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/retry.jpg";
        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);

        $disk->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        app(ToolImageService::class)->delete($toolImage);

        $this->assertDatabaseMissing('tool_images', ['id' => $toolImage->id]);
        $this->assertDatabaseHas('pending_tool_image_file_deletions', [
            'image_path' => $path,
            'attempts' => 1,
            'last_error' => 'Tool image file could not be deleted.',
        ]);
    }

    public function test_pending_file_deletions_can_be_retried(): void
    {
        Storage::fake('public');

        $path = 'tool-images/pending/retry.jpg';
        Storage::disk('public')->put($path, 'stored image');
        PendingToolImageFileDeletion::create([
            'image_path' => $path,
            'attempts' => 1,
            'last_error' => 'Previous failure.',
        ]);

        $this
            ->artisan('tool-images:delete-pending-files')
            ->expectsOutput('Deleted 1 pending tool image files.')
            ->assertSuccessful();

        $this->assertDatabaseCount('pending_tool_image_file_deletions', 0);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_pending_deletion_does_not_remove_a_referenced_file(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/still-referenced.jpg";

        Storage::disk('public')->put($path, 'stored image');
        ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);
        PendingToolImageFileDeletion::create([
            'image_path' => $path,
        ]);

        $this
            ->artisan('tool-images:delete-pending-files')
            ->expectsOutput('Deleted 0 pending tool image files.')
            ->assertSuccessful();

        $this->assertDatabaseCount('pending_tool_image_file_deletions', 0);
        Storage::disk('public')->assertExists($path);
    }

    public function test_deleting_tool_removes_stored_image_files(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/tool-delete.jpg";

        Storage::disk('public')->put($path, 'stored image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);

        $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->delete("/api/v1/tools/{$tool->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($tool);
        $this->assertDatabaseMissing('tool_images', ['id' => $toolImage->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_vendor_removes_stored_image_files(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/vendor-delete.jpg";

        Storage::disk('public')->put($path, 'stored image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);

        $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->delete("/api/v1/vendors/{$vendor->vendorProfile->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($vendor->vendorProfile);
        $this->assertSoftDeleted($tool);
        $this->assertDatabaseMissing('tool_images', ['id' => $toolImage->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_vendor_user_removes_stored_image_files(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $path = "tool-images/{$tool->id}/user-delete.jpg";

        Storage::disk('public')->put($path, 'stored image');

        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => $path,
        ]);

        $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->delete("/api/v1/users/{$vendor->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tool_images', ['id' => $toolImage->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_setting_main_image_clears_existing_main_image_for_tool(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $tool = $this->createToolForVendor($vendor);
        $oldMain = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => "tool-images/{$tool->id}/old-main.jpg",
            'is_main' => true,
        ]);

        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->post('/api/v1/tool-images', [
                'tool_id' => $tool->id,
                'image' => UploadedFile::fake()->image('new-main.jpg', 640, 480),
                'is_main' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('is_main', true);

        $this->assertFalse($oldMain->refresh()->is_main);
        $this->assertTrue(ToolImage::query()->whereKey($response->json('id'))->firstOrFail()->is_main);
    }

    public function test_moving_main_image_clears_existing_main_image_for_target_tool(): void
    {
        Storage::fake('public');

        $vendor = User::factory()->create(['role' => 'vendor']);
        $sourceTool = $this->createToolForVendor($vendor);
        $targetTool = $this->createToolForVendor($vendor, slug: 'saws');
        $movingMain = ToolImage::create([
            'tool_id' => $sourceTool->id,
            'image_path' => "tool-images/{$sourceTool->id}/moving-main.jpg",
            'is_main' => true,
        ]);
        $targetMain = ToolImage::create([
            'tool_id' => $targetTool->id,
            'image_path' => "tool-images/{$targetTool->id}/target-main.jpg",
            'is_main' => true,
        ]);

        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patch("/api/v1/tool-images/{$movingMain->id}", [
                'tool_id' => $targetTool->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('tool_id', $targetTool->id)
            ->assertJsonPath('is_main', true);

        $this->assertFalse($targetMain->refresh()->is_main);
        $this->assertTrue($movingMain->refresh()->is_main);
        $this->assertSame($targetTool->id, $movingMain->tool_id);
    }

    private function createToolForVendor(User $vendor, string $slug = 'drills'): Tool
    {
        $vendorProfile = VendorProfile::firstOrCreate(
            ['user_id' => $vendor->id],
            [
                'business_name' => "{$vendor->name} Rentals",
                'verification_status' => 'approved',
            ],
        );
        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug)],
        );

        return Tool::create([
            'vendor_id' => $vendorProfile->id,
            'category_id' => $category->id,
            'title' => 'Cordless drill',
            'price_per_day' => 20,
            'city' => 'Vilnius',
        ]);
    }
}
