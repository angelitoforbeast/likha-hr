<?php

namespace App\Http\Controllers;

use App\Models\FeaturePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeaturePermissionController extends Controller
{
    public function index()
    {
        // Only CEO can access
        if (Auth::user()->role !== 'ceo') {
            abort(403, 'Only CEO can manage feature permissions.');
        }

        $matrix = FeaturePermission::getMatrix();
        $roles = ['ceo' => 'CEO', 'admin' => 'Admin', 'hr_staff' => 'HR Staff'];

        return view('settings.feature-permissions', compact('matrix', 'roles'));
    }

    public function update(Request $request)
    {
        // Only CEO can update
        if (Auth::user()->role !== 'ceo') {
            abort(403, 'Only CEO can manage feature permissions.');
        }

        $permissions = $request->input('permissions', []);

        // Get all feature permissions
        $allPerms = FeaturePermission::all();

        foreach ($allPerms as $perm) {
            $viewKey = "permissions.{$perm->feature_key}.{$perm->role}.can_view";
            $editKey = "permissions.{$perm->feature_key}.{$perm->role}.can_edit";

            $canView = $request->has($viewKey) || data_get($permissions, "{$perm->feature_key}.{$perm->role}.can_view", false);
            $canEdit = $request->has($editKey) || data_get($permissions, "{$perm->feature_key}.{$perm->role}.can_edit", false);

            // If can_edit is true, can_view must also be true
            if ($canEdit) {
                $canView = true;
            }

            $perm->update([
                'can_view' => (bool) $canView,
                'can_edit' => (bool) $canEdit,
            ]);
        }

        return redirect()->route('settings.feature-permissions')
            ->with('success', 'Feature permissions updated successfully.');
    }
}
