<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeaturePermission extends Model
{
    protected $fillable = [
        'feature_key',
        'feature_label',
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
}
