<?php
namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvertCategoryModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Validator;
use Illuminate\Support\Facades\Auth;
class AdvertCategory extends Controller
{
public function makeCategory(Request $request)
{
    $user = Auth::guard('sanctum')->user();
    
     if (!$this->isAuthorized($user)) {
        return response()->json(['error' => 'Access not permitted for this user type'], 403);
    }
    // Define validation rules
    $validator = Validator::make($request->all(), [
        'title' => 'required|string|max:255',
        'icons.*' => 'required|mimes:png,jpg,jpeg|max:2048', // Accept multiple icons
        'excerpt' => 'required|string|max:320',
        'content' => 'required|string|max:5000',
    ]);

    // Check if validation fails
    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    // Save multiple icons to storage
    $iconPaths = [];
    if ($request->hasFile('icons')) {
        foreach ($request->file('icons') as $icon) {
            $iconPaths[] = $icon->store('icons', 'public'); // Save each icon to the public storage
        }
    }
    // Save the validated data into the AdvertCategoryModel
    $category = AdvertCategoryModel::create([
        'title' => $request->input('title'),
        'icon' => json_encode($iconPaths), // Store icons as a JSON array
        'excerpt' => $request->input('excerpt'),
        'content' => $request->input('content'),
    ]);

    // Respond with success message
    return response()->json([
        'success' => true,
        'message' => 'Advertisement category created successfully!',
    ], 201);
}



    // Retrieve all categories with pagination (Read)
    public function getAllCategories(Request $request)
    {
        // Define the number of items per page, or use a default value of 10
        $perPage = $request->input('per_page', 10);

        // Use the paginate method to retrieve categories with pagination
        $categories = AdvertCategoryModel::paginate($perPage);
        
        foreach($categories as $category){
            $category->icon = json_decode($category->icon);
            
        }

        return response()->json([
            'success' => true,
            'data' => $categories,
        ], 200);
    }

    // Retrieve a specific category by ID (Read)
    public function getCategoryById(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $category = AdvertCategoryModel::find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }
$category->icon =json_decode($category->icon); 
        return response()->json([
            'success' => true,
            'data' => $category,
        ], 200);
    }

 
public function updateCategory(Request $request)
{
    $user = Auth::guard('sanctum')->user();
     if (!$this->isAuthorized($user)) {
        return response()->json(['error' => 'Access not permitted for this user type'], 403);
    }
    $validator = Validator::make($request->all(), [
        'category_id' => 'required|integer|exists:advert_categories,id',
        'icons.*' => 'nullable|mimes:png,jpg,jpeg|max:2048', // Allow multiple optional icons
        'title' => 'sometimes|string|max:255',
        'excerpt' => 'sometimes|string|max:50',
        'content' => 'sometimes|string|max:255',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    $category = AdvertCategoryModel::find(strip_tags($request->input('category_id')));

    if (!$category) {
        return response()->json([
            'success' => false,
            'message' => 'Category not found',
        ], 404);
    }

    // Unlink (delete) old icons if new ones are provided
    if ($request->hasFile('icons')) {
        $oldIcons = $category->icons ? json_decode($category->icons, true) : [];
        foreach ($oldIcons as $oldIcon) {
            if (Storage::disk('public')->exists($oldIcon)) {
                Storage::disk('public')->delete($oldIcon); // Delete the old icon from storage
            }
        }
    }

    // Save new icons if provided
    $iconPaths = [];
    if ($request->hasFile('icons')) {
        foreach ($request->file('icons') as $icon) {
            $iconPaths[] = $icon->store('icons', 'public'); // Save each new icon
        }
    } else {
        $iconPaths = json_decode($category->icons, true); // Keep existing icons if none are provided
    }

    // Update the category with new data
    $category->update([
        'title' => $request->input('title', $category->title),
        'icons' => json_encode($iconPaths), // Store updated icons array
        'excerpt' => $request->input('excerpt', $category->excerpt),
        'content' => $request->input('content', $category->content),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Category updated successfully!',
        'data' => $category,
    ], 200);
}

    // Delete a specific category (Delete)
    public function deleteCategory(Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        
         if (!$this->isAuthorized($user)) {
        return response()->json(['error' => 'Access not permitted for this user type'], 403);
    }
        // Validate request data
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|integer|exists:advert_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find the category by ID
        $category = AdvertCategoryModel::find($request->input('category_id'));

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
             ], 404);
        }

        // Delete the category icon from storage
        if ($category->icon) {
            Storage::disk('public')->delete($category->icon);
        }

        // Delete the category from the database
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Advertisement category deleted successfully!',
        ], 200);
    }
      private function isAuthorized($user)
    {
        
        return in_array($user->user_type, ['admin', 'system_admin']);
    }
}

