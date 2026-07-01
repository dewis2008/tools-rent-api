<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Categories\Models\Category;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use Tests\TestCase;

class PublicCatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_browse_categories(): void
    {
        $category = Category::factory()->create();

        $this
            ->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $category->id);

        $this
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('id', $category->id);
    }

    public function test_guest_only_sees_publicly_available_tools_without_private_addresses(): void
    {
        $publicTool = Tool::factory()->create([
            'address' => 'Private pickup address',
            'status' => 'active',
        ]);
        Tool::factory()->create(['status' => 'pending']);
        Tool::factory()->create([
            'status' => 'active',
            'vendor_id' => VendorProfile::factory()->create([
                'verification_status' => 'pending',
            ]),
        ]);

        $response = $this
            ->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $publicTool->id);

        $this->assertArrayNotHasKey('address', $response->json('data.0'));

        $showResponse = $this
            ->getJson("/api/v1/tools/{$publicTool->id}")
            ->assertOk()
            ->assertJsonPath('id', $publicTool->id);

        $this->assertArrayNotHasKey('address', $showResponse->json());
    }

    public function test_tool_catalog_includes_stable_main_image_metadata_without_internal_paths(): void
    {
        $tool = Tool::factory()->create(['status' => 'active']);
        ToolImage::factory()->create([
            'tool_id' => $tool->id,
            'image_path' => "tool-images/{$tool->id}/secondary.jpg",
            'is_main' => false,
            'sort_order' => 1,
        ]);
        $mainImage = ToolImage::factory()->main()->create([
            'tool_id' => $tool->id,
            'image_path' => "tool-images/{$tool->id}/main.jpg",
        ]);

        $response = $this
            ->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonPath('data.0.main_image.id', $mainImage->id)
            ->assertJsonPath('data.0.main_image.tool_id', $tool->id)
            ->assertJsonPath('data.0.main_image.is_main', true)
            ->assertJsonPath('data.0.main_image.url', route('api.toolImages.file', $mainImage));

        $response->assertJsonMissing(['image_path' => $mainImage->image_path]);
    }

    public function test_tool_catalog_returns_null_main_image_when_tool_has_no_main_image(): void
    {
        $tool = Tool::factory()->create(['status' => 'active']);

        ToolImage::factory()->create([
            'tool_id' => $tool->id,
            'is_main' => false,
        ]);

        $this
            ->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonPath('data.0.main_image', null);
    }

    public function test_guest_cannot_view_an_unpublished_tool(): void
    {
        $tool = Tool::factory()->create(['status' => 'pending']);

        $this
            ->getJson("/api/v1/tools/{$tool->id}")
            ->assertForbidden();
    }

    public function test_guest_can_view_images_for_public_tools_only(): void
    {
        Storage::fake('local');

        $publicTool = Tool::factory()->create(['status' => 'active']);
        $publicPath = "tool-images/{$publicTool->id}/public.jpg";
        Storage::disk('local')->put($publicPath, 'public image');
        $publicImage = ToolImage::factory()->create([
            'tool_id' => $publicTool->id,
            'image_path' => $publicPath,
        ]);

        $privateTool = Tool::factory()->create(['status' => 'pending']);
        $privatePath = "tool-images/{$privateTool->id}/private.jpg";
        Storage::disk('local')->put($privatePath, 'private image');
        $privateImage = ToolImage::factory()->create([
            'tool_id' => $privateTool->id,
            'image_path' => $privatePath,
        ]);

        $this
            ->get("/api/v1/tool-images/{$publicImage->id}/file")
            ->assertOk();

        $this
            ->get("/api/v1/tool-images/{$privateImage->id}/file")
            ->assertForbidden();
    }

    public function test_catalog_mutations_still_require_authentication(): void
    {
        $category = Category::factory()->create();
        $tool = Tool::factory()->create();

        $this
            ->postJson('/api/v1/categories', [
                'name' => 'Drills',
                'slug' => 'drills',
            ])
            ->assertUnauthorized();

        $this
            ->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Updated'])
            ->assertUnauthorized();

        $this
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertUnauthorized();

        $this
            ->postJson('/api/v1/tools', [])
            ->assertUnauthorized();

        $this
            ->patchJson("/api/v1/tools/{$tool->id}", ['title' => 'Updated'])
            ->assertUnauthorized();

        $this
            ->deleteJson("/api/v1/tools/{$tool->id}")
            ->assertUnauthorized();
    }
}
