<?php

namespace Modules\Bookings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Bookings\Http\Requests\StoreBookingRequest;
use Modules\Bookings\Http\Requests\UpdateBookingRequest;
use Modules\Bookings\Models\Booking;

class BookingsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $query = Booking::query()->with(['tool', 'customer', 'vendor'])->latest();
        $user = request()->user();

        if ($user->role === 'vendor') {
            $query->where('vendor_id', $user->vendorProfile?->id ?? 0);
        }

        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        }

        return response()->json($query->paginate());
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $this->authorize('create', Booking::class);

        $booking = Booking::create($request->validated());

        return response()->json($booking->load(['tool', 'customer', 'vendor']), Response::HTTP_CREATED);
    }

    public function show(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return response()->json($booking->load(['tool', 'customer', 'vendor', 'payment', 'lockCode']));
    }

    public function update(UpdateBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        $booking->update($request->validated());

        return response()->json($booking->refresh()->load(['tool', 'customer', 'vendor', 'payment', 'lockCode']));
    }

    public function destroy(Booking $booking): Response
    {
        $this->authorize('delete', $booking);

        $booking->delete();

        return response()->noContent();
    }
}
