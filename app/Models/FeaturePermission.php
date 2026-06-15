<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeaturePermission extends Model
{
    protected $fillable = [
        'feature_key',
        'feature_label',
        'category',
        'role',
        'can_view',
        'can_edit',
        'sort_order',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_edit' => 'boolean',
    ];

    /**
     * Get all permissions grouped by feature for a specific role.
     */
    public static function getForRole(string $role): array
    {
        $perms = self::where('role', $role)->orderBy('sort_order')->get();
        $result = [];
        foreach ($perms as $p) {
            $result[$p->feature_key] = [
                'label'    => $p->feature_label,
                'can_view' => $p->can_view,
                'can_edit' => $p->can_edit,
            ];
        }
        return $result;
    }

    /**
     * Get the full permission matrix (all features x all roles).
     */
    public static function getMatrix(): array
    {
        $all = self::orderBy('sort_order')->orderByRaw("FIELD(role, 'ceo', 'admin', 'hr_staff')")->get();
        $matrix = [];
        foreach ($all as $p) {
            if (!isset($matrix[$p->feature_key])) {
                $matrix[$p->feature_key] = [
                    'label'      => $p->feature_label,
                    'sort_order' => $p->sort_order,
                    'roles'      => [],
                ];
            }
            $matrix[$p->feature_key]['roles'][$p->role] = [
                'can_view' => $p->can_view,
                'can_edit' => $p->can_edit,
            ];
        }
        return $matrix;
    }

    /**
     * Check if a role can view a feature.
     */
    public static function canView(string $role, string $featureKey): bool
    {
        $perm = self::where('role', $role)->where('feature_key', $featureKey)->first();
        return $perm ? $perm->can_view : false;
    }

    /**
     * Check if a role can edit a feature.
     */
    public static function canEdit(string $role, string $featureKey): bool
    {
        $perm = self::where('role', $role)->where('feature_key', $featureKey)->first();
        return $perm ? $perm->can_edit : false;
    }

    /**
     * Check if a role can access a nav item (used by route middleware).
     * CEO always returns true (URL access bypass — CEO cannot be locked out of routes).
     */
    public static function canAccessNav(string $role, string $navKey): bool
    {
        if ($role === 'ceo') return true;
        return self::canView($role, $navKey);
    }

    /**
     * Check if a nav item should appear in the sidebar for a given role (visual only).
     * Respects the per-role checkbox even for CEO so CEO can declutter their sidebar.
     * Note: URL access is still permitted via canAccessNav() for CEO.
     */
    public static function canSeeInSidebar(string $role, string $navKey): bool
    {
        return self::canView($role, $navKey);
    }

    /**
     * Get the full permission matrix filtered by category.
     */
    public static function getMatrixByCategory(string $category): array
    {
        $all = self::where('category', $category)
            ->orderBy('sort_order')
            ->orderByRaw("FIELD(role, 'ceo', 'admin', 'hr_staff')")
            ->get();
        $matrix = [];
        foreach ($all as $p) {
            if (!isset($matrix[$p->feature_key])) {
                $matrix[$p->feature_key] = [
                    'label'      => $p->feature_label,
                    'sort_order' => $p->sort_order,
                    'category'   => $p->category,
                    'roles'      => [],
                ];
            }
            $matrix[$p->feature_key]['roles'][$p->role] = [
                'can_view' => $p->can_view,
                'can_edit' => $p->can_edit,
            ];
        }
        return $matrix;
    }
}
