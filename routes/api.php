<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\Api\FlightController;
use App\Http\Controllers\V1\Api\UserController;
use App\Http\Controllers\V1\Api\SystemAdmin;
use App\Http\Controllers\V1\Api\PermissionController;
use App\Http\Controllers\V1\Api\InvoiceController;
use App\Http\Controllers\V1\Api\Countries;
use App\Http\Controllers\V1\Api\CorporateTravel;
use App\Http\Controllers\V1\Api\WalletController;
use App\Http\Controllers\V1\Api\ServiceCharge;
use App\Http\Controllers\V1\Api\PaymentController;
use App\Http\Controllers\V1\Api\TicketController;
use App\Http\Controllers\V1\Api\FlightManager;
use App\Http\Controllers\V1\Api\AdvertisementController;
use App\Http\Controllers\V1\Api\BlogCategory; 
use App\Http\Controllers\V1\Api\BlogController;
use App\Http\Controllers\V1\Api\AdvertCategory;
use App\Http\Controllers\V1\Api\ReviewController;


Route::get('/uche',  function(){
    return json_encode('ushe');
});
Route::post('/enquiry', [AdvertisementController::class, 'enquiry']);
Route::get('/user',  [UserController::class,'getUser'])->middleware('auth:sanctum');
Route::get('/flight/search/offer', [FlightController::class,'searchFlights']
)->name('search.flights');
Route::post('/flight/search/offer', [FlightController::class,'SearchMultiple']
);
Route::post('/advert/submit', [AdvertisementController::class,'makeAdvertisement']
);
Route::get('user/blogs/get', [BlogController::class, 'index']);

    // Get a specific blog post by ID
Route::get('user/blogs/{id}', [BlogController::class, 'show']);
Route::get('user/blog/categories/get/{id}', [BlogCategory::class, 'show']); 
Route::get('user/blog/categories', [BlogCategory::class, 'index']);

// Route::post('/flight/search/offer', function (Request $request) {
//     return response()->json($request->all());
// });
Route::post('flight/brand/fare', [FlightController::class,'BrandedFares']
);
Route::get('/flight/search/city', [FlightController::class,'citySearch']
)->name('search.city');
Route::post('/flight/price/confirm', [FlightController::class,'ConfirmPrice']
)->name('confirm.price');
Route::post('/flight/book', [FlightController::class,'BookFlight']
)->name('book.flight');
Route::get('flight/get/flight/details', [FlightController::class,'getFlightOrder']
)->name('flight.details');
Route::post('admin/register', [UserController::class,'registerAdmin']
)->name('register.admin');
Route::post('admin/verify/email', [UserController::class,'verifyEmail']
)->name('verify.email');
Route::post('admin/otp/regenerate', [UserController::class,'RegenerateOtp']);
Route::post('admin/change/password', [UserController::class,'ChangePassword']);
Route::post('/admin/login', [UserController::class,'login']);
Route::post('/user/login', [UserController::class,'login']);
Route::post('generate/payment', [PaymentController::class, 'initiatePay']); // Added ID parameter
Route::get('verify/payment', [PaymentController::class, 'verifyTransaction']);

Route::post('user/change/password', [UserController::class,'ChangePassword']);
Route::post('user/otp/regenerate', [UserController::class,'RegenerateOtp']);
Route::get('generate/ref', [FlightController::class, 'getToken']);
//getToken

Route::get('get/category/advert', [AdvertCategory::class, 'getAllCategories']);
Route::get('get/advertisements', [AdvertisementController::class, 'index']);

// Get a single advertisement by ID
Route::get('get/advertisement', [AdvertisementController::class, 'show']);

// Route to retrieve a specific advertisement category by ID
Route::get('get/category/advert/{id}', [AdvertCategory::class, 'getCategoryById']);
 Route::get('reviews/all', [ReviewController::class, 'getPublished']);
Route::middleware('auth:sanctum')->group(function () {
    
    Route::get('admin/blog/categories', [BlogCategory::class, 'index']);              // Get all categories
    Route::post('admin/create/blog/categories', [BlogCategory::class, 'create']);            // Create a new category
    Route::get('admin/blog/categories/get/{id}', [BlogCategory::class, 'show']);          // Show a specific category by ID
    Route::post('admin/blog/update/categories/{id}', [BlogCategory::class, 'update']);
    Route::post('admin/blog/delete/categories/{id}', [BlogCategory::class, 'destroy']);
    
    
    
    Route::prefix('admin')->group(function () {

    Route::get('reviews/all', [ReviewController::class, 'index']);

    Route::post('reviews', [ReviewController::class, 'store']);

    Route::get('reviews/single/{review}', [ReviewController::class, 'show']);

    Route::post('review/update/{review}', [ReviewController::class, 'update']);

    Route::post('delete/review/{review}', [ReviewController::class, 'destroy']);
});



Route::get('admin/blogs/get', [BlogController::class, 'index']);

    // Get a specific blog post by ID
Route::get('admin/blogs/{id}', [BlogController::class, 'show']);

    // Create a new blog post
Route::post('admin/create/blogs', [BlogController::class, 'create']);

    // Update a blog post by ID (using POST)
Route::post('admin/blogs/update/{id}', [BlogController::class, 'update']);
Route::post('admin/blogs/delete/{id}', [BlogController::class, 'destroy']);

// Route to retrieve all advertisement categories with pagination
Route::get('admin/get/category/advert', [AdvertCategory::class, 'getAllCategories']);

// Route to retrieve a specific advertisement category by ID
Route::get('admin/get/category/advert/{id}', [AdvertCategory::class, 'getCategoryById']);

// Route to update a specific advertisement category by ID
Route::post('admin/update/category/advert/{id}', [AdvertCategory::class, 'updateCategory']);
Route::post('admin/delete/category/advert', [AdvertCategory::class, 'deleteCategory']);



// List all advertisements with pagination
Route::get('admin/get/advertisements', [AdvertisementController::class, 'index']);

// Get a single advertisement by ID
Route::get('admin/get/advertisement', [AdvertisementController::class, 'show']);

// Create a new advertisement
Route::post('admin/create/advertisement', [AdvertisementController::class, 'store']);
Route::post('admin/create/category/advert', [AdvertCategory::class, 'makeCategory']);
// Update an existing advertisement by ID
Route::post('admin/update/advertisement', [AdvertisementController::class, 'update']);
Route::post('admin/delete/advertisement', [AdvertisementController::class, 'destroy']);
// Route to delete a specific advertisement category by ID
Route::get('admin/enquiries/all', [AdvertisementController::class, 'getEnquiries']);

Route::get('admin/get/flight/details', [FlightManager::class,'getFlightOrder']);
Route::post('admin/cancel/pnr', [FlightManager::class,'delete']);
Route::post('admin/modify/pnr', [FlightManager::class,'ModifyData']);

    Route::post('/logout', [UserController::class,'logout']
    )->name('logout');
    Route::post('owner/start/permission', [SystemAdmin::class,'addPermission']
)->name('owner.start.permission');
Route::post('create/permission', [PermissionController::class,'InitiateCreate']
)->name('create.permission');
Route::post('create/company', [SystemAdmin::class,'createUserByAdmin']
)->name('create.company');
Route::get('permissions/all', [PermissionController::class,'getAll']
)->name('permissions.all');
Route::post('assign/permission', [PermissionController::class,'assignPermission']
)->name('assign.permission');
//companies api
Route::post('org/create/staff', [UserController::class,'CreateStaff']
)->name('create.staff');
Route::post('create/invoice', [InvoiceController::class,'generate']);
Route::get('/countries', [Countries::class,'index']);
Route::post('/corporate/flight/book', [CorporateTravel::class, 'bookFlight']);
Route::get('users/all', [SystemAdmin::class,  'getAllUsers']);
Route::get('get/flights/booked', [SystemAdmin::class,  'getBooked']);

// For query parameter usage
Route::get('/users/single/{user_id}', [SystemAdmin::class, 'GetSingle']);

Route::post('user/edit', [SystemAdmin::class,  'updateUser']);
Route::get('corporate/wallet', [WalletController::class,  'index']);
Route::get('corporate/transaction/history',[WalletController::class,  'expenses']);
Route::get('corporate/test',[CorporateTravel::class,  'WalletPayment']);
 // MarkUp API
    Route::post('markup/create', [ServiceCharge::class, 'create']);
    Route::get('markup/home', [ServiceCharge::class, 'index']);
    Route::get('markup/show/{id}', [ServiceCharge::class, 'show']); // Added ID parameter
    Route::post('markup/update/{id}', [ServiceCharge::class, 'update']); // Added ID parameter
    Route::delete('markup/delete/{id}', [ServiceCharge::class, 'destroy']); // Added ID parameter
    //ticket api

       Route::get('flight/ticket', [TicketController::class, 'createTicket']);
        Route::post('flight/ticket', [TicketController::class, 'UpdateTicketStatus']);
});




