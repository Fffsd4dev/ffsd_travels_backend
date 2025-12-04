<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Wallet;
use App\Models\CompanyModel as Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Validator;
use App\Notifications\WelcomeEmail;
use App\Models\PaymentType as Payment_type;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\TicketStatus;
use App\Models\AssociatedRecords;
use App\Models\Travelers;
use App\Models\FlightOffers;
use Carbon\Carbon;
class SystemAdmin extends Controller
{
    public function addPermission(Request $request)
    {
        $user = Auth::guard('sanctum')->user();

        // Early return if source code doesn't match
        $data = $request->header('source_code');
        if (env('SOURCE_CODE') !== $data) {
            return response()->json(['error' => 'Invalid Access'], 422);
        }

        // Early return if user type is not authorized
        if (!in_array($user->user_type, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Invalid Access'], 422);
        }

        return $this->createInitial($user);
    }

    private function createInitial($user)
    {
        // Use transaction to ensure atomic operations
        return \DB::transaction(function () use ($user) {
            $permissions = ['create role', 'create permission', 'assign permission', 'create staff', 'create user'];
            $defaultGuard = 'sanctum';

            // Create role and assign to user
            $role = Role::create([
                'name' => 'super admin',
                'guard_name' => $defaultGuard,
                'created_by_user_id' => $user->id,
            ]);

            // Create permissions and assign to role and user
            foreach ($permissions as $permissionName) {
                $permission = Permission::create([
                    'name' => $permissionName,
                    'guard_name' => $defaultGuard,
                    'created_by_user_id' => $user->id,
                ]);
                $role->givePermissionTo($permission);
                $user->givePermissionTo($permission);
            }

            $user->assignRole($role);
            $user->update(['status' => true, 'user_type' => 'system_admin']);

            return response()->json(['success' => true, 'message' => 'Permissions set'], 201);
        });
    }

    public function createUserByAdmin(Request $request)
    {
        $user_logged = Auth::guard('sanctum')->user();

        if (!$user_logged || !in_array($user_logged->user_type, ['admin', 'system_admin']) || !$user_logged->can('create user')) {
            return response()->json(['error' => 'Invalid Access'], 422);
        }

        $rules = [
            'firstName' => 'required|string',
            'lastName' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'user_type' => 'required|in:admin,organization',
        ];

        if ($request->input('user_type') === 'organization') {
            $rules['companyName'] = 'required|string';
            $rules['companyCountry'] = 'required|numeric';
            $rules['paymentType'] = 'required|in:prepaid,postpaid';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Sanitize and prepare user data
        $userData = [
            'firstName' => filter_var($request->input('firstName'), FILTER_SANITIZE_STRING),
            'lastName' => filter_var($request->input('lastName'), FILTER_SANITIZE_STRING),
            'email' => filter_var($request->input('email'), FILTER_SANITIZE_EMAIL),
            'phone' => preg_replace('/[^0-9\s\-\+\(\)]/', '', $request->input('phone')),
            'password' => bcrypt(123456), // Consider using a more secure method for generating passwords
            'user_type' => filter_var($request->input('user_type'), FILTER_SANITIZE_STRING),
            'status' => 'inactive',
            'created_by_user_id' => $user_logged->id,
        ];

        $user = User::create($userData);

        if ($request->input('user_type') === 'organization') {
            // Sanitize and prepare company data
            $companyData = [
                'company_name' => filter_var($request->input('companyName'), FILTER_SANITIZE_STRING),
                'company_country_id' => filter_var($request->input('companyCountry'), FILTER_SANITIZE_STRING),
                'company_created_by_user_id' => $user->id,
                'payment_type' => filter_var($request->input('paymentType')),
            ];

            $comp = $this->createCompany($companyData);

            // Check for payment type and create wallet or invoice
            if ($request->input('paymentType') === 'prepaid') {
                // Create wallet account
                $wallet = Wallet::create([
                    'company_id' => $comp->id,
                    'created_by_user_id' => $user_logged->id,
                    'total_deposit' => 0,
                    'total_spent' => 0,
                    'balance' => 0,
                ]);

                // Create payment type record
                Payment_type::create([
                    'company_id' => $comp->id,
                    'created_by_user_id' => $user_logged->id,
                    'company_owner_user_id' => $user->id,
                    'payment_type' => 'prepaid',
                    'wallet_id' => $wallet->id,
                ]);

                // Create staff record
                Staff::create([
                    'company_id' => $comp->id,
                    'user_id' => $user->id,
                    'is_active' => true,
                    'created_by_user_id' => $user_logged->id,
                ]);
            }

            $user->update(['user_company_id' => $comp->id]);
        }

        $user->notify(new WelcomeEmail);
        return response()->json(['success' => true, 'message' => 'Account created successfully'], 201);
    }

    private function createCompany(array $data)
    {
        return Company::create($data);
    }

    public function getAllUsers()
    {
        try {
            // Eager load the createdByUser and company relationships
            $users = User::with(['createdByUser', 'paymentType', 'company'])
                ->where('user_type', '!=', 'system_admin')
                ->paginate(10);

            // Transform the users to include additional information
            $users->transform(function ($user) {
                if ($user->user_type === 'organization') {
                    $user->payment_type = $user->paymentType->payment_type ?? null;
                    $user->company = $user->company->company_name ?? null;
                }
                $user->created_by_user = $user->createdByUser;
                return $user;
            });

            return response()->json(['success' => true, 'users' => $users], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function updateUser(Request $request)
    {
        $user_logged = Auth::guard('sanctum')->user();

        if (!$user_logged || !in_array($user_logged->user_type, ['admin', 'system_admin']) || !$user_logged->can('create user')) {
            return response()->json(['error' => 'Invalid Access'], 403);
        }

        $rules = [
            'user_id' => 'required|exists:users,id',
            'firstName' => 'sometimes|string',
            'lastName' => 'sometimes|string',
            'email' => 'sometimes|email|unique:users,email,' . $request->input('user_id'),
            'phone' => ['sometimes', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'status' => 'sometimes|in:active,inactive',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($request->input('user_id'));
        $user->fill($request->only(['firstName', 'lastName', 'email', 'phone', 'status']));
        $user->save();

        if ($user->user_type === 'organization' && $request->hasAny(['companyName', 'companyCountry', 'paymentType'])) {
            $company = Company::find($user->user_company_id);
            if ($company) {
                $company->update(array_filter([
                    'company_name' => $request->input('companyName'),
                    'company_country_id' => $request->input('companyCountry'),
                    'payment_type' => $request->input('paymentType'),
                ]));
            }
        }

        return response()->json(['success' => true, 'message' => 'User updated successfully'], 200);
    }

    public function getSingle($id)
    {
        try {
            $user = User::with(['createdByUser', 'paymentType', 'company'])
                ->where('user_type', '!=', 'system_admin')
                ->findOrFail($id);

            if ($user->user_type === 'organization') {
                $user->payment_type = $user->paymentType->payment_type ?? null;
                $user->company = $user->company->company_name ?? null;
            }
            $user->created_by_user = $user->createdByUser;

            return response()->json(['success' => true, 'user' => $user], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
// public function getBooked(Request $request)
// {
//     $user_logged = Auth::guard('sanctum')->user();
//     if (!$user_logged || !in_array($user_logged->user_type, ['admin', 'system_admin'])) {
//         return response()->json(['error' => 'Invalid Access'], 403);
//     }

//     // Use eager loading to reduce query count
//     $booked_flights = TicketStatus::where('ticket_status', 'not done')
//         ->orderBy('created_at', 'desc')
//         ->with(['associatedRecords', 'travelers', 'flightOffer.travelerPricings','flightOffer.itineraries'])
//         ->paginate(15);
        
//     $booked_flights->getCollection()->transform(function ($flight) {
//         $flight->associatedRecords = $this->getPNR($flight->associatedRecords);
//         return [
//             'reference' => $flight->pnr,
//              // Ensure the correct key
//             'order_id' => $flight->flight_order_id,
//             'associated_records' => $flight->associatedRecords,
//             'Itineray'=>$flight->flightOffer->itineraries,
//             'travelers' => $flight->travelers,
//             'flight_offer' => $flight->flightOffer,
//             'traveler_pricings' => $flight->flightOffer ? $flight->flightOffer->travelerPricings : null, // Ensure flightOffer exists
//         ];
//     });

//     return response()->json($booked_flights);
// }

public function getBooked(Request $request)
{
    $user_logged = Auth::guard('sanctum')->user();
    if (!$user_logged || !in_array($user_logged->user_type, ['admin', 'system_admin'])) {
        return response()->json(['error' => 'Invalid Access'], 403);
    }

    // Use eager loading to reduce query count
    $booked_flights = TicketStatus::orderBy('created_at', 'desc')
        ->with(['associatedRecords', 'travelers', 'flightOffer.travelerPricings','flightOffer.itineraries'])
        ->paginate(15);
        
    $booked_flights->getCollection()->transform(function ($flight) {
        // Adjust the created_at to Nigeria time (Africa/Lagos)
        $created_at_nigeria_time = Carbon::parse($flight->created_at)->setTimezone('Africa/Lagos');

        $flight->associatedRecords = $this->getPNR($flight->associatedRecords);
        return [
            'ticket_status'=>TicketStatus::where('pnr', strip_tags($flight->pnr))->value('ticket_status'),
            'reference' => $flight->pnr,
            'order_id' => $flight->flight_order_id,
            'associated_records' => $flight->associatedRecords,
            'Itineray' => $flight->flightOffer->itineraries,
            'travelers' => $flight->travelers,
            'flight_offer' => $flight->flightOffer,
            'traveler_pricings' => $flight->flightOffer ? $flight->flightOffer->travelerPricings : null, // Ensure flightOffer exists
            'created_at' => $created_at_nigeria_time->format('Y-m-d H:i:s'), // Return formatted Nigeria time
        ];
    });

    return response()->json($booked_flights);
}
private function getPNR($associatedRecord)
{
    foreach ($associatedRecord as &$assoc) { // Use by reference to modify the array element
        $assoc['pnr'] = $assoc['reference']; // Correct key name, if it's an array
        unset($assoc['reference']); // Correctly unsetting
    }
    return $associatedRecord;
}


}
