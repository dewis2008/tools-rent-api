<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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

        $validated = $request->validated();
        $emailChanged = array_key_exists('email', $validated)
            && $validated['email'] !== $user->email;

        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->fill($validated)->save();

        if ($emailChanged
            || (array_key_exists('status', $validated) && $validated['status'] !== 'active')) {
            $user->tokens()->delete();
        }

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json($user->refresh());
    }

    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->noContent();
    }
}
