<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminAccountController extends Controller
{
    public function __construct(
        private readonly UserProfileService $userProfileService,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in(['admin', 'it'])],
            'region_ids' => ['required_if:role,admin', 'array'],
            'region_ids.*' => ['string', 'exists:regions,id'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $this->userProfileService->createAdminAccount([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'region_ids' => $data['region_ids'],
            'password' => $data['password'],
        ]);

        return to_route('kost.settings', ['tab' => 'admin']);
    }

    public function update(Request $request, string $user): RedirectResponse
    {
        $admin = $this->userProfileService->getAdminAccountById($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique(User::class, 'email')->ignore($admin->id)],
            'role' => ['required', Rule::in(['admin', 'it'])],
            'region_ids' => ['required_if:role,admin', 'array'],
            'region_ids.*' => ['string', 'exists:regions,id'],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
        ]);

        $this->userProfileService->updateAdminAccount($admin, [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'region_ids' => $data['region_ids'],
            'password' => $data['password'] ?? null,
        ]);

        return to_route('kost.settings', ['tab' => 'admin']);
    }

    public function destroy(Request $request, string $user): RedirectResponse
    {
        $admin = $this->userProfileService->getAdminAccountById($user);

        if ($request->user()?->id === $admin->id) {
            return back()->withErrors([
                'admin_delete' => 'Akun yang sedang dipakai tidak bisa dihapus dari halaman ini.',
            ]);
        }

        $this->userProfileService->deleteAdminAccount($admin);

        return to_route('kost.settings', ['tab' => 'admin']);
    }
}
