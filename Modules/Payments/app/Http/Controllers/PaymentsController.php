<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Payments\Http\Requests\StorePaymentRequest;
use Modules\Payments\Http\Requests\UpdatePaymentRequest;
use Modules\Payments\Models\Payment;

class PaymentsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['booking', 'customer'])->latest();
        $user = request()->user();

        if ($user->role === 'vendor') {
            $query->whereHas('booking', fn ($query) => $query->where('vendor_id', $user->vendorProfile?->id ?? 0));
        }

        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        }

        return response()->json($query->paginate());
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $payment = Payment::create($request->validated());

        return response()->json($payment->load(['booking', 'customer']), Response::HTTP_CREATED);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return response()->json($payment->load(['booking', 'customer']));
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('update', $payment);

        $payment->update($request->validated());

        return response()->json($payment->refresh()->load(['booking', 'customer']));
    }

    public function destroy(Payment $payment): Response
    {
        $this->authorize('delete', $payment);

        $payment->delete();

        return response()->noContent();
    }
}
