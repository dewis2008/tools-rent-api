<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Tools\Models\Tool;
use Tests\TestCase;

class CategoryDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_conflict_when_deleting_category_assigned_to_tool(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $tool = Tool::factory()->for($category)->create();

        $response = $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Categories assigned to tools cannot be deleted.');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('tools', ['id' => $tool->id]);
    }
}
