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
        return response()->json(Payment::query()->with(['booking', 'customer'])->latest()->paginate());
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $payment = Payment::create($request->validated());

        return response()->json($payment->load(['booking', 'customer']), Response::HTTP_CREATED);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json($payment->load(['booking', 'customer']));
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment->update($request->validated());

        return response()->json($payment->refresh()->load(['booking', 'customer']));
    }

    public function destroy(Payment $payment): Response
    {
        $payment->delete();

        return response()->noContent();
    }
}
