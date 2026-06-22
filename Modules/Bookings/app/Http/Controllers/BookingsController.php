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
        return response()->json(Booking::query()->with(['tool', 'customer', 'vendor'])->latest()->paginate());
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = Booking::create($request->validated());

        return response()->json($booking->load(['tool', 'customer', 'vendor']), Response::HTTP_CREATED);
    }

    public function show(Booking $booking): JsonResponse
    {
        return response()->json($booking->load(['tool', 'customer', 'vendor', 'payment', 'lockCode']));
    }

    public function update(UpdateBookingRequest $request, Booking $booking): JsonResponse
    {
        $booking->update($request->validated());

        return response()->json($booking->refresh()->load(['tool', 'customer', 'vendor', 'payment', 'lockCode']));
    }

    public function destroy(Booking $booking): Response
    {
        $booking->delete();

        return response()->noContent();
    }
}
