<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display user management page.
     * Each user sees only users whose roles they can manage.
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $manageableRoles = $currentUser->manageableRoles();

        $query = User::whereIn('role', $manageableRoles)
                     ->orderByRaw("FIELD(role, 'ceo', 'admin', 'hr_staff')")
                     ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $users = $query->paginate(20)->appends($request->query());

        $roleLabels = [
            User::ROLE_CEO      => 'CEO',
            User::ROLE_ADMIN    => 'Admin',
            User::ROLE_HR_STAFF => 'HR Staff',
        ];

        return view('users.index', compact('users', 'manageableRoles', 'roleLabels', 'currentUser'));
    }

    /**
     * Store a new user (created by an authenticated user).
     */
    public function store(Request $request)
    {
        $currentUser = Auth::user();
        $manageableRoles = $currentUser->manageableRoles();

        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'role'     => ['required', 'string', Rule::in($manageableRoles)],
        ], [
            'name.required'      => 'Name is required.',
            'email.required'     => 'Email is required.',
            'email.unique'       => 'This email is already taken.',
            'password.required'  => 'Password is required.',
            'password.min'       => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'role.required'      => 'Role is required.',
            'role.in'            => 'You are not allowed to assign this role.',
        ]);

        User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        return redirect()->route('users.index')
                         ->with('success', "User \"{$request->name}\" created successfully.");
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user)
    {
        $currentUser = Auth::user();

        if (!$currentUser->canManage($user)) {
            return redirect()->route('users.index')
                             ->with('error', 'You are not authorized to delete this user.');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('users.index')
                         ->with('success', "User \"{$name}\" deleted successfully.");
    }
}
