<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when($request->role, fn ($q, $r) => $q->where('role', $r))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['is_active' => true, 'role' => Role::Sales])]);
    }

    public function store(Request $request)
    {
        $user = User::create($this->validated($request));
        ActivityLog::record('created', "Menambah pengguna {$user->name} ({$user->role->label()})", $user);

        return redirect()->route('users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user)
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        if ($user->is(auth()->user()) && ($data['role'] !== Role::Admin->value || ! $data['is_active'])) {
            return back()->withInput()->with('error', 'Anda tidak dapat menurunkan peran atau menonaktifkan akun sendiri.');
        }

        if ($this->isLastAdmin($user) && ($data['role'] !== Role::Admin->value || ! $data['is_active'])) {
            return back()->withInput()->with('error', 'Minimal harus ada satu administrator aktif.');
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);
        ActivityLog::record('updated', "Mengubah pengguna {$user->name} ({$user->role->label()}".($user->is_active ? '' : ', nonaktif').')', $user);

        return redirect()->route('users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    public function destroy(User $user)
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        if ($this->isLastAdmin($user)) {
            return back()->with('error', 'Minimal harus ada satu administrator aktif.');
        }

        ActivityLog::record('deleted', "Menghapus pengguna {$user->name}");
        $user->delete();

        return back()->with('success', 'Pengguna berhasil dihapus.');
    }

    private function isLastAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active
            && User::where('role', Role::Admin)->where('is_active', true)->count() <= 1;
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ]);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
