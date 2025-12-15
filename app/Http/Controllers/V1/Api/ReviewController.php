<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewModel; // Consider renaming this to 'Review' for standard Laravel naming
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate; // Import Gate facade for better authorization

class ReviewController extends Controller
{
    /**
     * Define the model used for resource routes.
     * @var string
     */
    protected $model = ReviewModel::class; 

    /**
     * Get the middleware that should be assigned to the controller.
     * * @return array<int, \Illuminate\Routing\Controllers\Middleware|string>
     */
    public static function middleware(): array
    {
        // 2. Define your middleware in this static method
        return [
            // Apply 'auth:sanctum' to all methods EXCEPT getPublished
            new Middleware('auth:sanctum', except: ['getPublished']),

            // Apply the closure-based role check to the specified methods
            new Middleware(function (Request $request, $next) {
                $user = $request->user();

                if (!$user || !in_array($user->user_type, ['system_admin', 'admin'])) {
                    // Use abort(403) for cleaner unauthorized response
                    abort(403, 'Unauthorized action.'); 
                }

                return $next($request);
            }, only: ['index', 'store', 'show', 'update', 'destroy']),
        ];
    }
    public function index()
    {
        // Using `paginate` for potentially large result sets is better practice
        $reviews = ReviewModel::latest()->paginate(15); 

        return response()->json([
            'status' => true,
            // Use the data structure provided by Laravel's paginator
            'data' => $reviews->items(), 
            'meta' => [
                'total' => $reviews->total(),
                'per_page' => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
            ]
        ]);
    }

    /**
     * Public – published reviews only
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublished()
    {
        // It's good practice to also paginate public lists
        $reviews = ReviewModel::where('status', 'approved')
            ->latest()
            ->get(); 

        return response()->json([
            'status' => true,
            'reviews' => $reviews,
        ], 200);
    }

    /**
     * Create a new review (ADMIN ONLY)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // 1. **Best Practice:** Extract validation to a dedicated Request Class (e.g., `ReviewStoreRequest`).
        $validated = $request->validate([
            // Laravel's `bail` stops validation after the first failure
            'user_name' => 'bail|required|string|max:255', 
            'rating'    => 'bail|required|integer|min:1|max:5',
            'comment'   => 'nullable|string',
            // Enforce the allowed status values
            'status'    => 'nullable|in:pending,approved,rejected' 
        ]);

        // 2. **Shorthand:** Use `??` for null coalescing. (You already did this, which is good!)
        $validated['status'] ??= 'pending';

        $review = ReviewModel::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Review created successfully',
            'review' => $review
        ], 201); // 201 Created status code for resource creation
    }

    /**
     * Get single review (ADMIN ONLY)
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    // 3. **Best Practice:** Use Route Model Binding (ReviewModel $review)
    // The framework handles finding the model and returning 404 automatically.
    public function show(ReviewModel $review) 
    {
        // No need for $review = ReviewModel::find($id) and manual 404 check
        
        return response()->json([
            'status' => true,
            'review' => $review
        ]);
    }

    /**
     * Update a review (ADMIN ONLY)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    // 4. **Best Practice:** Use Route Model Binding
    public function update(Request $request, ReviewModel $review)
    {
        // No need for manual finding and 404 check
        
        // Use `sometimes` to ensure validation only runs if the field is present
        $validated = $request->validate([
            'user_name' => 'sometimes|string|max:255',
            'rating'    => 'sometimes|integer|min:1|max:5',
            'comment'   => 'nullable|string',
            'status'    => 'sometimes|in:pending,approved,rejected' // Use 'sometimes' here too
        ]);

        $review->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Review updated successfully',
            'review' => $review
        ]);
    }

    /**
     * Delete a review (ADMIN ONLY)
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    // 5. **Best Practice:** Use Route Model Binding
    public function destroy(ReviewModel $review)
    {
        // No need for manual finding and 404 check

        $review->delete();

        // 204 No Content status code is typically used for successful DELETE operations
        return response()->json([
            'status' => true,
            'message' => 'Review deleted successfully'
        ], 204); 
    }
}