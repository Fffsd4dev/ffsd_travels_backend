<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\ExpenseTracker;
use Illuminate\Support\Facades\Auth;
class WalletController extends Controller
{
    //
    public function index(){
        
      $user = Auth::guard('sanctum')->user();

        
        if (!$user || in_array($user->user_type, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Invalid Access'], 422);
        }
  
        $wallet_info = Wallet::where('company_id',$user->user_company_id)->first();
         return response()->json(['success' => true, 'data' =>$wallet_info ], 200);
    }
   public function expenses()
{
    $user = Auth::guard('sanctum')->user();

    if (!$user || in_array($user->user_type, ['admin', 'system_admin'])) {
        return response()->json(['error' => 'Invalid Access'], 422);
    }

    $expenses = ExpenseTracker::where('company_id', $user->user_company_id)->paginate(10);
//expenses should be filtered by date
    return response()->json(['success' => true, 'expenses' => $expenses], 200);
}

}
