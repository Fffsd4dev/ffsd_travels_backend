<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\permissionModel as Perm;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Validator;
// use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    //
    public function InitiateCreate(request $request){
        $user = Auth::guard('sanctum')->user();
        $defaultGuard = 'sanctum';
        if ($user) {
        
        if ($user && $user->can('create permission')) {
            
            $validator = Validator::make($request->all(), [
                'permission' => 'required|array',
                'permission.*' => 'required|string|unique:permissions,name',
            ]);
$permissions =$request->input('permission');
            foreach ($permissions as $permissionName) {
                $permission = Permission::create([
                    'name' => filter_var($permissionName, FILTER_SANITIZE_STRING),
                    'guard_name' => $defaultGuard,
                    'created_by_user_id' => $user->id,
                ]);
            }
             return response()->json(['message' => 'Permission created'], 201);
        } else {
            // The user does not have the 'can_create_permission' permission
            return response()->json(['message' => 'You do not have permission.'], 403);
        }
        
        


    }else{
        return response()->json(['message' => 'Invalid Access'], 422);
    }
}
private function CreatePermission($user, $permission){
    $defaultGuard = 'sanctum';
    foreach ($permissions as $permissionName) {
        $permission = PermissionModel::create([
            'name' => $permissionName,
            'guard_name' => $defaultGuard,
            'created_by_user_id' => $user->id,
        ]);

}
}
public function assignPermission(Request $request)
{
    // Get the authenticated user
    $user = Auth::guard('sanctum')->user();

    // Check if the user has the required permission
    if ($user && $user->can('create permission')) {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'perm_id' => 'required|array',
            'perm_id.*' => 'required|numeric',
            'assign_user_id' => 'required|numeric',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Retrieve the user to assign permissions to
        $user2assign = User::find($request->input('assign_user_id'));

        if (!$user2assign) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Retrieve the permissions
        $permissions = Permission::whereIn('id', $request->input('perm_id'))->get();

        if ($permissions->isEmpty()) {
            return response()->json(['error' => 'Permission(s) not found'], 404);
        }

        // Assign each permission to the user
        foreach ($permissions as $permission) {
            if (!$user2assign->hasPermissionTo($permission)) {
                $user2assign->givePermissionTo($permission);
            }
        }

        return response()->json(['message' => 'Permission(s) assigned successfully'], 200);
    }

    // If the user is not authenticated or does not have the required permission
    return response()->json(['error' => 'Unauthorized'], 403);
}
    public function getAll()
    {
        $user = Auth::guard('sanctum')->user();

        if ($user && $user->can('create permission')) {
            $permissions = Permission::paginate(10);
            return response()->json($permissions, 200);
           
        }

        return response()->json(['error' => 'Unauthorized'], 403);
    }
}

