<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MarkUp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ServiceCharge extends Controller
{
    //
public function create(Request $request)
{
    $user = Auth::guard('sanctum')->user();

    // Check if the user is authorized
    if (!$this->isAuthorized($user)) {
        return response()->json(['error' => 'Access not permitted for this user type'], 403);
    }

    // Convert fee_name to lowercase
    $fee_name = strtolower($request->fee_name);

    // Define the validation rules
    $validator = Validator::make($request->all(), [
        'fee_name' => [
            'required', 
            'string', 
            Rule::unique('mark_ups', 'fee_name')->where(function ($query) use ($fee_name) {
                return $query->whereRaw('LOWER(fee_name) = ?', [$fee_name]);
            })
        ], // Ensures unique and case-insensitive check
        'fee_percentage' => ['required', 'numeric', 'between:0,100'],
    ]);

    // Check if the validation fails
    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Create the MarkUp using the lowercase fee_name
    $markUp = MarkUp::create([
        'fee_name' => $fee_name, // Save in lowercase
        'fee_percentage' => $request->fee_percentage,
        'created_by_user_id' => $user->id, // Use the authenticated user's ID
    ]);

    return response()->json(['message' => 'MarkUp created successfully', 'data' => $markUp], 201);
}


    // Retrieve all MarkUps
    public function index()
    {
        $markUps = MarkUp::all();
        return response()->json(['data' => $markUps], 200);
    }

    // Retrieve a specific MarkUp by ID
    public function show($id)
    {
        $markUp = MarkUp::find($id);

        if (!$markUp) {
            return response()->json(['error' => 'MarkUp not found'], 404);
        }

        return response()->json(['data' => $markUp], 200);
    }

    // Update a specific MarkUp by ID
    public function update(Request $request, $id)
    {
        $user = Auth::guard('sanctum')->user();

        if (!$this->isAuthorized($user)) {
            return response()->json(['error' => 'Access not permitted for this user type'], 403);
        }
      
        $markUp = MarkUp::find(strip_tags($id));


        if (!$markUp) {
            return response()->json(['error' => 'MarkUp not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'fee_name' => ['sometimes', 'string'],
            'fee_percentage' => ['sometimes', 'numeric', 'between:0,100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update the MarkUp instance
        $markUp->update($request->only(['fee_name', 'fee_percentage']));

        return response()->json(['message' => 'MarkUp updated successfully'], 200);
    }

    // Delete a specific MarkUp by ID
    public function destroy($id)
    {
        $user = Auth::guard('sanctum')->user();

        if (!$this->isAuthorized($user)) {
            return response()->json(['error' => 'Access not permitted for this user type'], 403);
        }

        $markUp = MarkUp::find($id);

        if (!$markUp) {
            return response()->json(['error' => 'MarkUp not found'], 404);
        }

        $markUp->delete();

        return response()->json(['message' => 'MarkUp deleted successfully'], 200);
    }

    // Check if the user is authorized
    private function isAuthorized($user)
    {
        return in_array($user->user_type, ['admin', 'system_admin']);
    }
}
