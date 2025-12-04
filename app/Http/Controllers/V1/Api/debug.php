// $canCreatePermission = $user->can('can_create_permission', 'sanctum'); // Check permission with sanctum guard
    
        // Debugging: Detailed permission check
       // $userPermissions = $user->permissions->pluck('name')->toArray();
        // $rolePermissions = $user->getRoleNames()->flatMap(function ($roleName) {
        //     $role = Role::findByName($roleName, 'sanctum');
        //     return $role->permissions->pluck('name')->toArray();
        // });
        $roles = $user->getRoleNames(); // Get user roles
        // $permissions = $user->getAllPermissions()->pluck('name'); // Get all user permissions