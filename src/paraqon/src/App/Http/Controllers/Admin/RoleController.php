<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

/**
 * Paraqon-only role extensions (PII field_masking).
 * Base /api/admin/roles remains unchanged for tcg-bid compatibility.
 */
class RoleController extends Controller
{
    public function updateRoleFieldMasking(Request $request): array
    {
        /** @var ?Role $role */
        $role = Role::find($request->route('id'));
        if (is_null($role)) {
            abort(404, 'Role not found');
        }

        $fieldMasking = $request->input('field_masking', []);
        $normalized = [
            'name' => $fieldMasking['name'] ?? 'none',
            'email' => $fieldMasking['email'] ?? 'none',
            'phone' => $fieldMasking['phone'] ?? 'none',
        ];

        foreach (['name', 'email', 'phone'] as $field) {
            if (!in_array($normalized[$field], ['all', 'partial', 'none'], true)) {
                abort(422, "Invalid field_masking.{$field}");
            }
        }

        $role->update(['field_masking' => $normalized]);

        return [
            'message' => 'Updated Role field_masking successfully',
            'field_masking' => $normalized,
        ];
    }
}
