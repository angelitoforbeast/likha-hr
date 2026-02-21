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
     * Each user sees only users whose roles they can manage, plus themselves.
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $manageableRoles = $currentUser->manageableRoles();

        $query = User::where(function ($q) use ($manageableRoles, $currentUser) {
                $q->whereIn('role', $manageableRoles)
                  ->orWhere('id', $currentUser->id); // Always show self
            })
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
     * Show the edit form for a user.
     */
    public function edit(User $user)
    {
        $currentUser = Auth::user();

        if (!$currentUser->canEdit($user)) {
            return redirect()->route('users.index')
                             ->with('error', 'You are not authorized to edit this user.');
        }

        $assignableRoles = $currentUser->assignableRolesFor($user);
        $roleLabels = [
            User::ROLE_CEO      => 'CEO',
            User::ROLE_ADMIN    => 'Admin',
            User::ROLE_HR_STAFF => 'HR Staff',
        ];

        $isSelf = $currentUser->id === $user->id;

        return view('users.edit', compact('user', 'currentUser', 'assignableRoles', 'roleLabels', 'isSelf'));
    }

    /**
     * Update a user's info (name, email, role, password).
     */
    public function update(Request $request, User $user)
    {
        $currentUser = Auth::user();

        if (!$currentUser->canEdit($user)) {
            return redirect()->route('users.index')
                             ->with('error', 'You are not authorized to edit this user.');
        }

        $isSelf = $currentUser->id === $user->id;
        $assignableRoles = $currentUser->assignableRolesFor($user);

        // Build validation rules
        $rules = [
            'name'  => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ];

        // Role validation: only if not editing self
        if (!$isSelf) {
            $rules['role'] = ['required', 'string', Rule::in($assignableRoles)];
        }

        // Password is optional on edit
        if ($request->filled('password')) {
            $rules['password'] = 'string|min:6|confirmed';
        }

        $messages = [
            'name.required'      => 'Name is required.',
            'email.required'     => 'Email is required.',
            'email.unique'       => 'This email is already taken.',
            'password.min'       => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'role.in'            => 'You are not allowed to assign this role.',
        ];

        $request->validate($rules, $messages);

        // Update fields
        $user->name  = $request->name;
        $user->email = $request->email;

        // Only update role if not editing self
        if (!$isSelf && $request->filled('role')) {
            $user->role = $request->role;
        }

        // Update password if provided
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        $redirect = $isSelf ? redirect()->route('users.edit', $user) : redirect()->route('users.index');

        return $redirect->with('success', "User \"{$user->name}\" updated successfully.");
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
