<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Ichtrojan\Otp\Otp as OTP;
use App\Notifications\OtpEmail;
use App\Notifications\WelcomeEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\PaymentType;
use App\Models\Staff;
class UserController extends Controller
{
    private $otp;

    public function __construct()
    {
        $this->otp = new OTP;
    }

    public function registerAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string',
            'lastName' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'password' => 'required|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = [
            'firstName' => filter_var($request->input('firstName'), FILTER_SANITIZE_STRING),
            'lastName' => filter_var($request->input('lastName'), FILTER_SANITIZE_STRING),
            'email' => filter_var($request->input('email'), FILTER_SANITIZE_EMAIL),
            'phone' => preg_replace('/[^0-9\s\-\+\(\)]/', '', $request->input('phone')),
            'password' => bcrypt(strip_tags($request->input('password'))),
            'user_type'=>'admin',
        ];
        $status = User::where('user_type','system_admin')->first();
        if($status){
              return response()->json(['success' => false, 'message' => 'super admin already exists'], 422); 
           
            
        }
         $user = $this->createUser($data);
            $this-> createInitial($user);
        $this->generateOtp($user);

        return response()->json([
            'message' => 'Admin registered successfully. Kindly use the OTP sent to your email to verify your account.',
            'user' => $user,
            'success'=>true
        ]);
    }

    private function createUser(array $data)
    {
        return User::create($data);
    }

    private function generateOtp(User $user)
    {
        $output = $this->otp->generate($user->email, 'numeric', 6, 15);
        $user->otp = $output->token;
        $user->notify(new OtpEmail($output->token)); // Pass OTP token to the notification
        unset($user->otp); // Unset OTP from user instance for security reasons
        return $user;
    }

    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|exists:users,email',
            'otp' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = [
            'firstName' => filter_var($request->input('firstName'), FILTER_SANITIZE_STRING),
            'lastName' => filter_var($request->input('lastName'), FILTER_SANITIZE_STRING),
            'email' => filter_var($request->input('email'), FILTER_SANITIZE_EMAIL),
            'phone' => preg_replace('/[^0-9\s\-\+\(\)]/', '', $request->input('phone')),
            'password' => bcrypt(strip_tags($request->input('password'))),
            'user_type'=>'admin',
            'created_by_user_id'=>null,
        ];


        $email = filter_var($request->input('email'),FILTER_SANITIZE_EMAIL);
        $otp = strip_tags($request->input('otp'));

        $status = $this->otp->validate($email, $otp);

        if ($status->status) {
            User::where('email', $email)->update(['email_verified_at' => now(), 'status' => true]);
            return response()->json(['message' => 'Email address confirmed. You can log in now.', 'success'=>true ], 201);
        } else {
            return response()->json(['message' => $status->message, 'success'=>false], 422);
        }
    }

    public function regenerateOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $email = $request->input('email');
        $email = filter_var($email,FILTER_SANITIZE_EMAIL);
        $user = User::where('email', $email)->first();

        $output = $this->otp->generate($user->email, 'numeric', 6, 15);
        $user->otp = $output->token;
        $user->notify(new OtpEmail($output->token)); // Pass OTP token to the notification
        unset($user->otp); // Unset OTP from user instance for security reasons

        return response()->json([
            'message' => 'OTP sent successfully. Use the OTP sent to your email to validate your account.',
            'user' => $user,
            'success'=>true,
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|confirmed',
            'otp' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $email = filter_var($request->input('email'),FILTER_SANITIZE_EMAIL);
        $otp = strip_tags($request->input('otp'));
        $email = $request->input('email');
        $password = $request->input('password');
        $otp = (int)$request->input('otp');

        $user = User::where('email', $email)->first();
        

        $status = $this->otp->validate($email, $otp);

        if ($status->status) {
            $user->password = bcrypt(strip_tags($request->input('password')));
            $user->save();

            return response()->json(['message' => 'Password changed successfully. You can now log in.','success'=>true], 201);
        } else {
            return response()->json(['message' => $status->message,'success'=>false], 422);
        }
    }

    public function login(Request $request)
{
  $validator = Validator::make($request->all(), [
      'email' => 'required|email',
      'password' => 'required|string',
  ]);

  if ($validator->fails()) {
      return response()->json(['errors' => $validator->errors()], 422);
  }

  $credentials = request(['email', 'password']);
  
  if (Auth::attempt($credentials)) {
    $user = Auth::user();
    $token = $user->createToken('api-token')->plainTextToken;
    $info =$user::where('id',$user->id)->update([
        'api_token' =>$token,
    ]);
    return response()->json([
        'token' => $token,
        'user' => $user,
        'message' => 'You have logged in successfully',
        'success' => true
    ], 201);
} else {
    return response()->json(['message' => 'Invalid credentials'], 401);
}
        
        

    }
    public function logout(request $request){

            $request->user()->tokens()->delete();
             return response()->json(['message' => 'Logged out successfully']);
     
         
     
    }

    public function createStaff(request $request){
        $user = Auth::guard('sanctum')->user();

        if ($user) {
        
            if ($user 
            //&& $user->can('create staff')
            ) {

                $rules = [
                    'firstName' => 'required|string',
                    'lastName' => 'required|string',
                    'email' => 'required|email|unique:users,email',
                    'phone' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
                ];
                
                $validator = Validator::make($request->all(), $rules);
        
                if ($validator->fails()) {
                    return response()->json(['errors' => $validator->errors()], 422);
                }
                $user_company_id = $user->user_company_id;
            
                $userData = [
                    'firstName' => filter_var($request->input('firstName'), FILTER_SANITIZE_STRING),
                    'lastName' => filter_var($request->input('lastName'), FILTER_SANITIZE_STRING),
                    'email' => filter_var($request->input('email'), FILTER_SANITIZE_EMAIL),
                    'phone' => preg_replace('/[^0-9\s\-\+\(\)]/', '', $request->input('phone')),
                    'password' => bcrypt(123456), // Consider using a more secure method for generating passwords
                    'user_type' =>'staff',
                    'created_by_user_id'=>$user->id,
                    'user_company_id'=>$user_company_id,
                    'status'=>'inactive',
                ];

                $Saveduser = $this->createUser($userData);
            
                Staff::create(
                    ['company_id'=>$Saveduser->user_company_id,
                    'user_id'=>$Saveduser->id,
                    'is_active'=>false,
                    'created_by_user_id'=>$user->id,
                    ]);
                
                $Saveduser->notify(new WelcomeEmail); // 
        
                return response()->json([
                    'message' => 'User created successfully',
                    'user' =>$Saveduser,
                    'success'=>true,
                ]);

            }else{
                return response()->json(['success' => false, 'message' => 'Invalid access'], 422); 
            }
        
        
        
        }




       
    }
    
        private function createInitial($user)
    {
    
        // Use transaction to ensure atomic operations
        return \DB::transaction(function () use ($user) {
            $permissions = ['create role', 'create permission', 'assign permission', 'create staff', 'create user'];
            $defaultGuard = 'sanctum';
            //Auth::getDefaultDriver();

            // Create role and assign to user
            $role = Role::create([
                'name' => 'super admin',
                'guard_name' => $defaultGuard,
                'created_by_user_id' => $user->id,
            ]);

            // Create permissions and assign to role and user in one go
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

            // Update system admin status
            $user->update(['status' => true, 'user_type' => 'system_admin']);

            return response()->json(['success' => true, 'message' => 'Permissions set'], 201);
        });
    }
    
public function getUser()
{
    $user = Auth::guard('sanctum')->user();

    if (!$user) {
        return response()->json(['success' => false, 'message' => 'Invalid access'], 422);
    }

    $user['created_by_details'] = User::where('id', $user->created_by_user_id)->first();

    if (!in_array($user->user_type, ['admin', 'system_admin'])) {
        // Retrieve payment details first
        $user['payment_details'] = PaymentType::where('company_id', $user->user_company_id)->value('payment_type');

        // Then unset the sensitive data
        unset($user->created_by_user_id);
       // unset($user->user_company_id);
    }

    return response()->json(['success' => true, 'user' => $user], 200);
}


    public function updateUser(Request $request) {
        $user = Auth::guard('sanctum')->user();
    
        if ($user) {
            if ($user->can('create staff')) {
                $rules = [
                    'user_id' => 'required|string',
                    'firstName' => 'sometimes|string',
                    'lastName' => 'sometimes|string',
                    'email' => 'sometimes|email|unique:users,email',
                    'phone' => ['sometimes', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
                ];
    
                $validator = Validator::make($request->all(), $rules);
    
                if ($validator->fails()) {
                    return response()->json(['errors' => $validator->errors()], 422);
                }
    
                $userData = [];
    
                if ($request->has('firstName')) {
                    $userData['firstName'] = filter_var($request->input('firstName'), FILTER_SANITIZE_STRING);
                }
    
                if ($request->has('lastName')) {
                    $userData['lastName'] = filter_var($request->input('lastName'), FILTER_SANITIZE_STRING);
                }
    
                if ($request->has('email')) {
                    $userData['email'] = filter_var($request->input('email'), FILTER_SANITIZE_EMAIL);
                }
    
                if ($request->has('phone')) {
                    $userData['phone'] = preg_replace('/[^0-9\s\-\+\(\)]/', '', $request->input('phone'));
                }
    
    
                if (!empty($userData)) {
                    $userData['updated_by_user_id'] = $user->id;
                    $userData['user_company_id'] = $user->company_id;
    
                    // Update the user
                    User::where('id', $request->input('user_id'))->update($userData);
    
                    return response()->json([
                        'message' => 'User modified successfully',
                        'user' => $userData,
                        'success' => true,
                    ]);
                } else {
                    return response()->json([
                        'message' => 'No data to update',
                        'success' => false,
                    ], 400);
                }
            } else {
                return response()->json(['success' => false, 'message' => 'Invalid access'], 422);
            }
        }
    }
    
}
