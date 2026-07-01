<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\Payments\Models\Payment;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use Tests\TestCase;

class ListFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tool_catalog_can_be_searched_filtered_sorted_and_paginated(): void
    {
        $drills = Category::factory()->create(['name' => 'Drills']);
        $saws = Category::factory()->create(['name' => 'Saws']);
        $vendor = VendorProfile::factory()->create(['business_name' => 'Trusted Rentals']);

        $matchingTools = collect([
            Tool::factory()->create([
                'vendor_id' => $vendor->id,
                'category_id' => $drills->id,
                'title' => 'Compact Hammer Drill',
                'price_per_day' => 32,
                'city' => 'Vilnius',
                'status' => 'active',
            ]),
            Tool::factory()->create([
                'vendor_id' => $vendor->id,
                'category_id' => $drills->id,
                'title' => 'Professional Hammer Drill',
                'price_per_day' => 48,
                'city' => 'Vilnius',
                'status' => 'active',
            ]),
        ]);

        Tool::factory()->create([
            'vendor_id' => $vendor->id,
            'category_id' => $saws->id,
            'title' => 'Hammer Drill in wrong category',
            'price_per_day' => 40,
            'city' => 'Vilnius',
        ]);
        Tool::factory()->create([
            'vendor_id' => $vendor->id,
            'category_id' => $drills->id,
            'title' => 'Hammer Drill in wrong city',
            'price_per_day' => 40,
            'city' => 'Kaunas',
        ]);
        Tool::factory()->create([
            'vendor_id' => $vendor->id,
            'category_id' => $drills->id,
            'title' => 'Hammer Drill outside price range',
            'price_per_day' => 80,
            'city' => 'Vilnius',
        ]);

        $response = $this
            ->getJson('/api/v1/tools?'.http_build_query([
                'query' => 'hammer',
                'category' => $drills->id,
                'city' => 'vilnius',
                'min_price' => 30,
                'max_price' => 50,
                'status' => 'active',
                'page_size' => 1,
                'sort_by' => 'price',
                'sort_direction' => 'desc',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $matchingTools->last()->id);

        $this->assertStringContainsString('query=hammer', $response->json('links.next'));
    }

    public function test_admin_can_filter_each_management_list(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin-client')->plainTextToken;
        $targetOwner = User::factory()->vendor()->create([
            'name' => 'North Owner',
            'status' => 'active',
        ]);
        $targetVendor = VendorProfile::factory()->create([
            'user_id' => $targetOwner->id,
            'business_name' => 'North Rentals',
            'verification_status' => 'approved',
            'rating' => 4.8,
        ]);
        $otherVendor = VendorProfile::factory()->create([
            'business_name' => 'South Rentals',
            'verification_status' => 'pending',
            'rating' => 2.5,
        ]);
        $category = Category::factory()->create();
        $targetTool = Tool::factory()->create([
            'vendor_id' => $targetVendor->id,
            'category_id' => $category->id,
            'title' => 'Admin Search Excavator',
            'status' => 'pending',
        ]);
        Tool::factory()->create([
            'vendor_id' => $otherVendor->id,
            'category_id' => $category->id,
            'title' => 'Other Excavator',
            'status' => 'active',
        ]);
        $customer = User::factory()->customer()->create([
            'name' => 'Target Customer',
            'email' => 'target.customer@example.com',
        ]);
        $otherCustomer = User::factory()->customer()->create();
        $targetBooking = Booking::factory()->paid()->create([
            'tool_id' => $targetTool->id,
            'vendor_id' => $targetVendor->id,
            'customer_id' => $customer->id,
            'start_at' => '2026-08-10 10:00:00',
            'end_at' => '2026-08-12 10:00:00',
            'total_amount' => 120,
        ]);
        Booking::factory()->create([
            'vendor_id' => $otherVendor->id,
            'customer_id' => $otherCustomer->id,
            'status' => 'pending',
            'start_at' => '2026-09-10 10:00:00',
            'end_at' => '2026-09-12 10:00:00',
        ]);
        $targetPayment = Payment::factory()->paid()->create([
            'booking_id' => $targetBooking->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_admin_search_target',
            'amount' => 120,
            'currency' => 'EUR',
        ]);
        Payment::factory()->create([
            'customer_id' => $otherCustomer->id,
            'provider' => 'demo',
            'amount' => 20,
        ]);

        $this
            ->withToken($token)
            ->getJson('/api/v1/tools?'.http_build_query([
                'query' => 'Admin Search',
                'vendor_id' => $targetVendor->id,
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetTool->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/bookings?'.http_build_query([
                'query' => 'Target Customer',
                'status' => 'paid',
                'tool_id' => $targetTool->id,
                'customer_id' => $customer->id,
                'vendor_id' => $targetVendor->id,
                'date_from' => '2026-08-11',
                'date_to' => '2026-08-11',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetBooking->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/vendors?'.http_build_query([
                'query' => 'North',
                'verification_status' => 'approved',
                'user_status' => 'active',
                'min_rating' => 4,
                'max_rating' => 5,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetVendor->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/payments?'.http_build_query([
                'query' => 'admin_search',
                'status' => 'paid',
                'provider' => 'stripe',
                'currency' => 'eur',
                'booking_id' => $targetBooking->id,
                'customer_id' => $customer->id,
                'vendor_id' => $targetVendor->id,
                'min_amount' => 100,
                'max_amount' => 130,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetPayment->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/users?'.http_build_query([
                'query' => 'target.customer',
                'role' => 'customer',
                'status' => 'active',
                'email_verified' => true,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->id);
    }

    public function test_vendor_filters_remain_limited_to_vendor_access_scope(): void
    {
        $vendorUser = User::factory()->vendor()->create();
        $vendor = VendorProfile::factory()->create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Scoped Vendor Rentals',
            'verification_status' => 'approved',
        ]);
        $otherVendor = VendorProfile::factory()->create();
        $category = Category::factory()->create();
        $ownTool = Tool::factory()->create([
            'vendor_id' => $vendor->id,
            'category_id' => $category->id,
            'title' => 'Owned Pending Drill',
            'status' => 'pending',
        ]);
        Tool::factory()->create([
            'vendor_id' => $otherVendor->id,
            'category_id' => $category->id,
            'title' => 'Other Pending Drill',
            'status' => 'pending',
        ]);
        $customer = User::factory()->customer()->create(['name' => 'Scoped Customer']);
        $ownBooking = Booking::factory()->paid()->create([
            'tool_id' => $ownTool->id,
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
        ]);
        $otherBooking = Booking::factory()->paid()->create([
            'vendor_id' => $otherVendor->id,
            'customer_id' => $customer->id,
        ]);
        $ownPayment = Payment::factory()->paid()->create([
            'booking_id' => $ownBooking->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
        ]);
        Payment::factory()->paid()->create([
            'booking_id' => $otherBooking->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
        ]);
        $token = $vendorUser->createToken('vendor-client')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/tools?query=Pending&status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownTool->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/bookings?'.http_build_query([
                'query' => 'Scoped Customer',
                'status' => 'paid',
                'customer_id' => $customer->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownBooking->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/payments?provider=stripe&status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPayment->id);

        $this
            ->withToken($token)
            ->getJson('/api/v1/vendors?query=Scoped&verification_status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vendor->id);
    }

    public function test_management_lists_support_allowlisted_sorting_and_page_sizes(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin-client')->plainTextToken;
        $users = collect([
            User::factory()->customer()->create(['name' => 'Alpha Sorted User']),
            User::factory()->customer()->create(['name' => 'Beta Sorted User']),
        ]);

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/users?'.http_build_query([
                'query' => 'Sorted User',
                'page_size' => 1,
                'sort_by' => 'name',
                'sort_direction' => 'asc',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $users->first()->id)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1);

        $this->assertStringContainsString('sort_by=name', $response->json('next_page_url'));
    }

    public function test_list_filters_reject_invalid_ranges_and_sort_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin-client')->plainTextToken;

        $this
            ->getJson('/api/v1/tools?'.http_build_query([
                'category' => 999999,
                'min_price' => 50,
                'max_price' => 10,
                'page_size' => 101,
                'sort_by' => 'vendor_id; drop table tools',
                'sort_direction' => 'sideways',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'category',
                'max_price',
                'page_size',
                'sort_by',
                'sort_direction',
            ]);

        $this
            ->withToken($token)
            ->getJson('/api/v1/bookings?date_from=2026-08-12&date_to=2026-08-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $this
            ->withToken($token)
            ->getJson('/api/v1/vendors?min_rating=5&max_rating=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_rating');

        $this
            ->withToken($token)
            ->getJson('/api/v1/payments?min_amount=100&max_amount=10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_amount');

        $this
            ->withToken($token)
            ->getJson('/api/v1/users?email_verified=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_verified');
    }
}
