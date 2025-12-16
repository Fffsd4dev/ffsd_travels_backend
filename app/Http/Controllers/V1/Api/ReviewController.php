<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewModel;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * List all reviews (ADMIN & SYSTEM ADMIN ONLY)
     * Fixed: Added Request $request to parameters
     */
    public function index(Request $request)
    {
        $this->checkAccess($request);
        
        $reviews = ReviewModel::latest()->paginate(15);

        return response()->json([
            'status' => true,
            'data'   => $reviews->items(),
            'meta'   => [
                'total'        => $reviews->total(),
                'per_page'     => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
            ]
        ]);
    }

    /**
     * Published reviews only (PUBLIC)
     */
    public function getPublished()
    {
        $reviews = ReviewModel::where('status', 'approved')
            ->latest()
            ->paginate(15);

        return response()->json([
            'status'  => true,
            'reviews' => $reviews,
        ]);
    }

    /**
     * Create review (ADMIN & SYSTEM ADMIN ONLY)
     */
    public function store(Request $request)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'user_name' => 'required|string|max:255',
            'rating'    => 'required|integer|min:1|max:5',
            'comment'   => 'nullable|string',
            'status'    => 'nullable|in:pending,approved,rejected',
        ]);

        $validated['status'] ??= 'pending';

        $review = ReviewModel::create($validated);

        return response()->json([
            'status'  => true,
            'message' => 'Review created successfully',
            'review'  => $review
        ], 201);
    }

    /**
     * Show single review (ADMIN & SYSTEM ADMIN ONLY)
     */
    public function show(Request $request, ReviewModel $review)
    {
        $this->checkAccess($request);

        return response()->json([
            'status' => true,
            'review' => $review
        ]);
    }

    /**
     * Update review (ADMIN & SYSTEM ADMIN ONLY)
     * Fixed: Removed $id. Laravel already finds the record via ReviewModel $review.
     */
    public function update(Request $request, ReviewModel $review)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'user_name' => 'sometimes|string|max:255',
            'rating'    => 'sometimes|integer|min:1|max:5',
            'comment'   => 'nullable|string',
            'status'    => 'sometimes|in:pending,approved,rejected',
        ]);

        $review->update($validated);

        return response()->json([
            'status'  => true,
            'message' => 'Review updated successfully',
            'review'  => $review
        ]);
    }

    /**
     * Delete review (ADMIN & SYSTEM ADMIN ONLY)
     */
    public function destroy(Request $request, ReviewModel $review)
    {
        $this->checkAccess($request);

        $review->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Review deleted successfully'
        ]);
    }

    /**
     * Restrict admin-only actions
     */
    private function checkAccess(Request $request): void
    {
        $user = $request->user();

        if (!$user || !in_array($user->user_type, ['system_admin', 'admin'])) {
            abort(403, 'Unauthorized action.');
        }
    }
}