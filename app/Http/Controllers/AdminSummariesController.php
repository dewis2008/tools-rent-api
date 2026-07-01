<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminSummariesResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\Payments\Models\Payment;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class AdminSummariesController extends Controller
{
    public function show(): JsonResponse
    {
        return (new AdminSummariesResource([
            'users' => User::query()->count(),
            'vendors' => VendorProfile::query()->count(),
            'categories' => Category::query()->count(),
            'tools' => Tool::query()->count(),
            'bookings' => Booking::query()->count(),
            'payments' => Payment::query()->count(),
            'pending_vendors' => VendorProfile::query()->where('verification_status', 'pending')->count(),
            'pending_tools' => Tool::query()->where('status', 'pending')->count(),
            'active_bookings' => Booking::query()->where('status', 'active')->count(),
        ]))->response();
    }
}
