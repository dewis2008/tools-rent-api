<?php

namespace Tests\Feature;

use App\Enums\ApiErrorCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\Payments\Models\Payment;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_availability_uses_the_same_exclusive_overlap_rules_as_bookings(): void
    {
        $tool = Tool::factory()->create();
        $startAt = now()->addDays(2)->startOfHour();
        $endAt = $startAt->copy()->addDays(2);

        $this
            ->getJson($this->availabilityUrl($tool, $startAt, $endAt))
            ->assertOk()
            ->assertJsonPath('tool_id', $tool->id)
            ->assertJsonPath('available', true);

        Booking::factory()->paid()->create([
            'tool_id' => $tool->id,
            'vendor_id' => $tool->vendor_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);

        $this
            ->getJson($this->availabilityUrl($tool, $startAt, $endAt))
            ->assertOk()
            ->assertJsonPath('available', false);

        $this
            ->getJson($this->availabilityUrl($tool, $endAt, $endAt->copy()->addDay()))
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_admin_summary_returns_dashboard_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $vendor = VendorProfile::factory()->create();
        $category = Category::factory()->create();
        $tool = Tool::factory()->create([
            'vendor_id' => $vendor->id,
            'category_id' => $category->id,
            'status' => 'pending',
        ]);
        $booking = Booking::factory()->active()->create([
            'tool_id' => $tool->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
        ]);
        Payment::factory()->create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
        ]);

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->getJson('/api/v1/admin/summary')
            ->assertOk()
            ->assertJson([
                'users' => 3,
                'vendors' => 1,
                'categories' => 1,
                'tools' => 1,
                'bookings' => 1,
                'payments' => 1,
                'pending_vendors' => 0,
                'pending_tools' => 1,
                'active_bookings' => 1,
            ]);
    }

    public function test_admin_summary_requires_authentication(): void
    {
        $this
            ->getJson('/api/v1/admin/summary')
            ->assertUnauthorized()
            ->assertJsonPath('code', ApiErrorCode::Unauthenticated->value);
    }

    public function test_admin_summary_rejects_non_admin_users(): void
    {
        $customer = User::factory()->customer()->create();

        $this
            ->withToken($customer->createToken('customer-client')->plainTextToken)
            ->getJson('/api/v1/admin/summary')
            ->assertForbidden()
            ->assertJsonPath('code', ApiErrorCode::Forbidden->value);
    }

    public function test_api_errors_include_stable_machine_readable_codes(): void
    {
        $tool = Tool::factory()->create();

        $this
            ->getJson("/api/v1/tools/{$tool->id}/availability")
            ->assertUnprocessable()
            ->assertJsonPath('code', ApiErrorCode::ValidationFailed->value)
            ->assertJsonValidationErrors(['start_at', 'end_at']);

        $this
            ->getJson('/api/v1/tools/999999')
            ->assertNotFound()
            ->assertJsonPath('code', ApiErrorCode::NotFound->value);
    }

    public function test_resource_collections_share_standard_and_legacy_pagination_metadata(): void
    {
        Category::factory()->count(2)->create();

        $this
            ->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'created_at', 'updated_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('total', 2);
    }

    private function availabilityUrl(Tool $tool, CarbonInterface $startAt, CarbonInterface $endAt): string
    {
        return "/api/v1/tools/{$tool->id}/availability?".http_build_query([
            'start_at' => $startAt->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
        ]);
    }
}
