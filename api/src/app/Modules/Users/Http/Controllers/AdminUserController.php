<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Users\Actions\ChangeAccountType;
use App\Modules\Users\Actions\ManageAccountStatus;
use App\Modules\Users\Actions\ManageUsers;
use App\Modules\Users\Actions\SyncUserRoles;
use App\Modules\Users\Enums\AccountType;
use App\Modules\Users\Http\Requests\BanUserRequest;
use App\Modules\Users\Http\Requests\ChangeAccountTypeRequest;
use App\Modules\Users\Http\Requests\ListUsersRequest;
use App\Modules\Users\Http\Requests\StoreUserRequest;
use App\Modules\Users\Http\Requests\SyncUserRolesRequest;
use App\Modules\Users\Http\Requests\UpdateUserRequest;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AdminUserController extends Controller
{
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $users = User::query()
            ->with('roles')
            ->when($filters['account_type'] ?? null, fn ($q, $type) => $q->where('account_type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'banned' => $q->whereNotNull('banned_at'),
                'deactivated' => $q->whereNull('banned_at')->whereNotNull('deactivated_at'),
                'active' => $q->usable(),
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = addcslashes(mb_strtolower($search), '%_\\').'%';
                $q->where(fn ($q) => $q->where('username', 'like', $term)->orWhere('email', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function store(StoreUserRequest $request, ManageUsers $users): JsonResponse
    {
        $user = $users->create($request->user(), $request->validated());

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user, ManageUsers $users): UserResource
    {
        return new UserResource($users->update($request->user(), $user, $request->validated())->load('roles'));
    }

    public function activate(Request $request, User $user, ManageAccountStatus $status): UserResource
    {
        return new UserResource($status->activate($request->user(), $user)->load('roles'));
    }

    public function deactivate(Request $request, User $user, ManageAccountStatus $status): UserResource
    {
        return new UserResource($status->deactivate($request->user(), $user)->load('roles'));
    }

    public function ban(BanUserRequest $request, User $user, ManageAccountStatus $status): UserResource
    {
        return new UserResource($status->ban($request->user(), $user, $request->validated('reason'))->load('roles'));
    }

    public function unban(Request $request, User $user, ManageAccountStatus $status): UserResource
    {
        return new UserResource($status->unban($request->user(), $user)->load('roles'));
    }

    public function changeAccountType(ChangeAccountTypeRequest $request, User $user, ChangeAccountType $change): UserResource
    {
        return new UserResource($change($request->user(), $user, AccountType::from($request->validated('account_type')))->load('roles'));
    }

    public function revokeSessions(Request $request, User $user, ManageAccountStatus $status): Response
    {
        $status->revokeSessions($request->user(), $user);

        return response()->noContent();
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user, SyncUserRoles $sync): UserResource
    {
        return new UserResource($sync($request->user(), $user, array_map('intval', $request->validated('roles'))));
    }
}
