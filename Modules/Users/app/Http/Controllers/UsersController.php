<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Users\Http\Requests\StoreUserRequest;
use Modules\Users\Http\Requests\UpdateUserRequest;

class UsersController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json(User::query()->latest()->paginate());
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = User::create($request->validated());

        event(new Registered($user));

        return response()->json($user, Response::HTTP_CREATED);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json($user);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $emailChanged = DB::transaction(function () use ($request, $user): bool {
            $validated = $request->validated();
            $user->fill($validated);

            $emailChanged = $user->isDirty('email');
            $authenticationOrAuthorizationChanged = $user->isDirty([
                'email',
                'password',
                'role',
            ]);

            if ($emailChanged) {
                $user->forceFill(['email_verified_at' => null]);
            }

            $user->save();

            if (! $user->isEligibleVendor()) {
                $user->vendorProfile?->tools()
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
            }

            if ($authenticationOrAuthorizationChanged
                || (array_key_exists('status', $validated) && $validated['status'] !== 'active')) {
                $user->tokens()->delete();
            }

            return $emailChanged;
        });

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json($user->refresh());
    }

    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        if ($user->hasBookingHistory()) {
            throw new AuthorizationException(__('Users with booking history cannot be deleted.'));
        }

        $user->delete();

        return response()->noContent();
    }
}
