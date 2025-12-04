<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewModel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * List all reviews
     */
    public function index()
    {
        return response()->json([
            'status' => true,
            'reviews' => ReviewModel::latest()->get()
        ]);
    }

    /**
     * Create a new review
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_name' => 'required|string|max:255',
            'rating'    => 'required|integer|min(1)|max(5)',
            'comment'   => 'nullable|string',
            'status'    => 'nullable|in:pending,approved,rejected'
        ]);

        $validated['status'] = $validated['status'] ?? 'pending';

        $review = ReviewModel::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Review created successfully',
            'review' => $review
        ], 201);
    }

    /**
     * Get single review
     */
    public function show($id)
    {
        $review = ReviewModel::find($id);

        if (!$review) {
            return response()->json([
                'status' => false,
                'message' => 'Review not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'review' => $review
        ]);
    }

    /**
     * Update a review
     */
    public function update(Request $request, $id)
    {
        $review = ReviewModel::find($id);

        if (!$review) {
            return response()->json([
                'status' => false,
                'message' => 'Review not found'
            ], 404);
        }

        $validated = $request->validate([
            'user_name' => 'sometimes|string|max:255',
            'rating'    => 'sometimes|integer|min(1)|max(5)',
            'comment'   => 'nullable|string',
            'status'    => 'nullable|in:pending,approved,rejected'
        ]);

        $review->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Review updated successfully',
            'review' => $review
        ]);
    }

    /**
     * Delete a review
     */
    public function destroy($id)
    {
        $review = ReviewModel::find($id);

        if (!$review) {
            return response()->json([
                'status' => false,
                'message' => 'Review not found'
            ], 404);
        }

        $review->delete();

        return response()->json([
            'status' => true,
            'message' => 'Review deleted successfully'
        ]);
    }
}
