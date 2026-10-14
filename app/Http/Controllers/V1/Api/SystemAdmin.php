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



public function getPendings(Request $request)
{
    $user_logged = Auth::guard('sanctum')->user();
    if (!$user_logged || !in_array($user_logged->user_type, ['admin', 'system_admin'])) {
        return response()->json(['error' => 'Invalid Access'], 403);
    }

    $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

    // 1. Payments that are still pending
    $pendingPayments = Payment::where('payment_status', 'pending')
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);

    $payments = $pendingPayments->getCollection();

    // 2. Flight search sessions linked to those payments
    //    (by confirmation idempotency key, falling back to the ama-client-ref)
    $idempotencyKeys = $payments->pluck('idempotency_key')->filter()->unique()->values();
    $clientRefs = $payments->pluck('ama_client_ref')->filter()->unique()->values();

    $sessions = collect();

    if ($idempotencyKeys->isNotEmpty() || $clientRefs->isNotEmpty()) {
        $sessions = FlightSearchSession::query()
            ->where(function ($query) use ($idempotencyKeys, $clientRefs) {
                if ($idempotencyKeys->isNotEmpty()) {
                    $query->whereIn('idempotency_key', $idempotencyKeys);
                }

                if ($clientRefs->isNotEmpty()) {
                    $query->orWhereIn('ama_client_ref', $clientRefs);
                }
            })
            ->get();
    }

    $sessionsByKey = $sessions->filter(fn ($s) => !empty($s->idempotency_key))
        ->keyBy('idempotency_key');
    $sessionsByRef = $sessions->filter(fn ($s) => !empty($s->ama_client_ref))
        ->keyBy('ama_client_ref');

    // 3. User details (only the fields the admin needs)
    $userIds = $sessions->pluck('user_id')
        ->merge($payments->pluck('user_id'))
        ->filter()
        ->unique()
        ->values();

    $users = $userIds->isEmpty()
        ? collect()
        : User::whereIn('id', $userIds)
            ->get(['id', 'firstName', 'lastName', 'email', 'phone', 'user_type', 'user_company_id'])
            ->keyBy('id');

    // 4. Shape the response
    $pendingPayments->getCollection()->transform(
        function ($payment) use ($sessionsByKey, $sessionsByRef, $users) {
            $session = ($payment->idempotency_key ? $sessionsByKey->get($payment->idempotency_key) : null)
                ?? ($payment->ama_client_ref ? $sessionsByRef->get($payment->ama_client_ref) : null);

            $userId = $payment->user_id ?? $session?->user_id;
            $user = $userId ? $users->get($userId) : null;

            return [
                'payment' => [
                    'id'                 => $payment->id,
                    'reference'          => $payment->reference ?? null,
                    'amount'             => $payment->amount ?? null,
                    'status'             => $payment->payment_status,
                    'paid_by_email'      => $payment->paid_by_email ?? null,
                    'flight_order_am_id' => $payment->flight_order_am_id ?? null,
                    'idempotency_key'    => $payment->idempotency_key ?? null,
                    'created_at'         => Carbon::parse($payment->created_at)
                        ->setTimezone('Africa/Lagos')
                        ->format('Y-m-d H:i:s'),
                ],
                'user' => $user
                    ? [
                        'id'         => $user->id,
                        'name'       => trim($user->firstName . ' ' . $user->lastName),
                        'email'      => $user->email,
                        'phone'      => $user->phone,
                        'user_type'  => $user->user_type,
                        'company_id' => $user->user_company_id,
                        'guest'      => false,
                    ]
                    : [
                        'guest' => true,
                        'email' => $payment->paid_by_email ?? null,
                    ],
                'flight' => $session
                    ? [
                        'ama_client_ref'       => $session->ama_client_ref,
                        'session_status'       => $session->status,
                        'search'               => $this->summarizeSearchCriteria($session->search_criteria),
                        'pricing'              => [
                            'currency'      => $session->flight_currency,
                            'flight_fee'    => $session->flight_fee,
                            'service_fee'   => $session->service_fee,
                            'total_payable' => $session->total_payable,
                        ],
                        'confirmed_at'         => $session->confirmed_at?->toIso8601String(),
                        'confirmation_expires' => $session->expires_at?->toIso8601String(),
                        'confirmation_expired' => $session->expires_at ? $session->expires_at->isPast() : null,
                    ]
                    : null,
            ];
        }
    );

    return response()->json($pendingPayments);
}

/**
 * Turns the stored search criteria into a readable summary.
 * Handles both one-way/round-trip GET searches and multi-city POST searches.
 */
private function summarizeSearchCriteria($criteria): array
{
    if (is_string($criteria)) {
        $criteria = json_decode($criteria, true);
    }

    if (!is_array($criteria)) {
        return [];
    }

    // Multi-city / multi-leg search
    if (!empty($criteria['originDestinations']) && is_array($criteria['originDestinations'])) {
        $legs = array_map(fn ($leg) => [
            'origin'      => $leg['originLocationCode'] ?? null,
            'destination' => $leg['destinationLocationCode'] ?? null,
            'date'        => $leg['departureDateTimeRange']['date'] ?? null,
            'time'        => $leg['departureDateTimeRange']['time'] ?? null,
        ], $criteria['originDestinations']);

        $passengers = ['adults' => 0, 'children' => 0, 'infants' => 0];

        foreach (($criteria['travelers'] ?? []) as $traveler) {
            $type = $traveler['travelerType'] ?? 'ADULT';

            if ($type === 'CHILD') {
                $passengers['children']++;
            } elseif (in_array($type, ['HELD_INFANT', 'SEATED_INFANT'], true)) {
                $passengers['infants']++;
            } else {
                $passengers['adults']++;
            }
        }

        $isRoundTrip = count($legs) === 2
            && $legs[0]['origin'] === $legs[1]['destination']
            && $legs[0]['destination'] === $legs[1]['origin'];

        return [
            'trip_type'  => $isRoundTrip ? 'round_trip' : 'multi_city',
            'legs'       => $legs,
            'passengers' => $passengers,
            'cabin'      => $criteria['searchCriteria']['flightFilters']['cabinRestrictions'][0]['cabin'] ?? null,
            'currency'   => $criteria['currencyCode'] ?? null,
        ];
    }

    // Standard search (query-string parameters)
    $legs = [[
        'origin'      => $criteria['originLocationCode'] ?? null,
        'destination' => $criteria['destinationLocationCode'] ?? null,
        'date'        => $criteria['departureDate'] ?? null,
        'time'        => null,
    ]];

    if (!empty($criteria['returnDate'])) {
        $legs[] = [
            'origin'      => $criteria['destinationLocationCode'] ?? null,
            'destination' => $criteria['originLocationCode'] ?? null,
            'date'        => $criteria['returnDate'],
            'time'        => null,
        ];
    }

    return [
        'trip_type'  => count($legs) > 1 ? 'round_trip' : 'one_way',
        'legs'       => $legs,
        'passengers' => [
            'adults'   => (int) ($criteria['adults'] ?? 1),
            'children' => (int) ($criteria['children'] ?? 0),
            'infants'  => (int) ($criteria['infants'] ?? 0),
        ],
        'cabin'      => $criteria['travelClass'] ?? null,
        'currency'   => $criteria['currencyCode'] ?? null,
    ];
}

}
