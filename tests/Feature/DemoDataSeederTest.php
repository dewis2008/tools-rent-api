<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Bookings\Database\Seeders\BookingsDatabaseSeeder;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\LockCodes\Models\LockCode;
use Modules\Payments\Models\Payment;
use Modules\ToolImages\Database\Seeders\ToolImagesDatabaseSeeder;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;
use Modules\Users\Database\Seeders\UsersDatabaseSeeder;
use Modules\Vendors\Models\VendorProfile;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_connected_demo_data(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@tools-rent.test',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'vendor@tools-rent.test',
            'role' => 'vendor',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'customer@tools-rent.test',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('vendor_profiles', 2);
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('tools', 5);
        $this->assertDatabaseCount('tool_images', 10);
        $this->assertDatabaseCount('bookings', 4);
        $this->assertDatabaseCount('payments', 4);
        $this->assertDatabaseCount('lock_codes', 2);

        Tool::query()->each(function (Tool $tool): void {
            $this->assertSame(1, $tool->images()->where('is_main', true)->count());
        });
    }

    public function test_module_factories_create_valid_rental_records(): void
    {
        $category = Category::factory()->create();
        $vendorProfile = VendorProfile::factory()->create();
        $tool = Tool::factory()->create([
            'category_id' => $category->id,
            'vendor_id' => $vendorProfile->id,
        ]);
        $toolImage = ToolImage::factory()->main()->create([
            'tool_id' => $tool->id,
        ]);
        $booking = Booking::factory()->paid()->create([
            'tool_id' => $tool->id,
        ]);
        $payment = Payment::factory()->paid()->create([
            'booking_id' => $booking->id,
        ]);
        $lockCode = LockCode::factory()->active()->create([
            'booking_id' => $booking->id,
        ]);

        $this->assertSame($tool->vendor_id, $booking->vendor_id);
        $this->assertSame($booking->customer_id, $payment->customer_id);
        $this->assertSame($booking->total_amount, $payment->amount);
        $this->assertSame($booking->id, $lockCode->booking_id);
        $this->assertTrue($toolImage->is_main);
    }

    public function test_demo_users_require_explicit_seeding_opt_in(): void
    {
        config()->set('app.allow_demo_seeding', false);

        try {
            $this->seed(UsersDatabaseSeeder::class);
            $this->fail('Expected demo user seeding to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Demo users can only be seeded when ALLOW_DEMO_SEEDING is enabled.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_booking_seeder_reuses_existing_demo_booking_when_status_changes(): void
    {
        $this->seed();

        $tool = Tool::query()->where('title', 'Cordless Hammer Drill')->firstOrFail();
        $customer = User::query()->where('email', 'customer@tools-rent.test')->firstOrFail();
        $booking = Booking::query()
            ->where('tool_id', $tool->id)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $booking->update(['status' => 'paid']);

        $this->seed(BookingsDatabaseSeeder::class);

        $this->assertSame(1, Booking::query()
            ->where('tool_id', $tool->id)
            ->where('customer_id', $customer->id)
            ->count());
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'pending',
        ]);
    }

    public function test_tool_image_seeder_only_updates_demo_tools(): void
    {
        $this->seed();

        $tool = Tool::factory()->create([
            'title' => 'Non Demo Drill',
        ]);
        $toolImage = ToolImage::factory()->main()->create([
            'tool_id' => $tool->id,
            'image_path' => 'uploads/tool-images/non-demo.jpg',
            'sort_order' => 7,
        ]);
        $demoTool = Tool::query()->where('title', 'Cordless Hammer Drill')->firstOrFail();

        $demoTool->images()->update(['is_main' => false]);
        ToolImage::factory()->main()->create([
            'tool_id' => $demoTool->id,
            'sort_order' => 7,
        ]);

        $this->seed(ToolImagesDatabaseSeeder::class);

        $this->assertSame(1, $tool->images()->count());
        $this->assertDatabaseHas('tool_images', [
            'id' => $toolImage->id,
            'image_path' => 'uploads/tool-images/non-demo.jpg',
            'is_main' => true,
            'sort_order' => 7,
        ]);
        $this->assertSame(1, $demoTool->images()->where('is_main', true)->count());
    }
}
