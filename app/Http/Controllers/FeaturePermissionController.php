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

        $navMatrix      = FeaturePermission::getMatrixByCategory('navigation');
        $employeeMatrix = FeaturePermission::getMatrixByCategory('employee_section');
        $roles = ['ceo' => 'CEO', 'admin' => 'Admin', 'hr_staff' => 'HR Staff'];

        return view('settings.feature-permissions', compact('navMatrix', 'employeeMatrix', 'roles'));
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
            // Read the actual submitted values — hidden inputs default to "0" when checkbox unchecked
            $viewVal = data_get($permissions, "{$perm->feature_key}.{$perm->role}.can_view", '0');
            $editVal = data_get($permissions, "{$perm->feature_key}.{$perm->role}.can_edit", '0');

            $canView = ($viewVal === '1' || $viewVal === 1 || $viewVal === true);
            $canEdit = ($editVal === '1' || $editVal === 1 || $editVal === true);

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
