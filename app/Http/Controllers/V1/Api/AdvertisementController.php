<?php


namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvertisementModel;
use App\Models\Enquiries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Mail\Enquiries as EnquiryMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Mail\AdvertisementMail;

class AdvertisementController extends Controller
{
   public function makeAdvertisement(Request $request)
    {
        // Define validation rules
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'fullName' => 'required|string|max:160',
            'location' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0', // Corrected to use 'numeric' for amounts
            'phone' => [
                'required', 
                'regex:/^(\+?\d{1,3})?[-.\s]?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}$/', 
                'max:15'
            ],
            'email'=>'required|email',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $advertisementData = $request->only(['title', 'fullName', 'location', 'destination', 'amount', 'phone','email']);
        
    
        $this->SendViaEmail($advertisementData);

        // Proceed with saving the advertisement or other business logic
        return response()->json([
            'success' => true,
            'message' => 'Advertisement created successfully!',
        ], 201);
    }

   
    // List all advertisements
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // Default to 10
        $advertisements = AdvertisementModel::with('category')->paginate($perPage);
        return response()->json($advertisements);
    }

    // Show single advertisement
    public function show(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'advert_id' => 'required|integer|exists:advertisements,id'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $advertisement = AdvertisementModel::with('category')->findOrFail($request->input('advert_id'));
        return response()->json($advertisement);
    }

    // Create a new advertisement
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $imagePaths = $this->saveImages($request);

        $advertisement = AdvertisementModel::create(array_merge($validator->validated(), [
            'featured_images' => json_encode($imagePaths)
        ]));

        return $this->successResponse('Advertisement created successfully', $advertisement, 201);
    }

    // Update existing advertisement
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules(true));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $advertisement = AdvertisementModel::findOrFail($request->input('advert_id'));

        $imagePaths = $request->hasFile('featured_images') 
            ? $this->saveImages($request) 
            : $advertisement->featured_images;

        $advertisement->update(array_merge($validator->validated(), [
            'featured_images' => is_array($imagePaths) ? json_encode($imagePaths) : $imagePaths
        ]));

        return $this->successResponse('Advertisement updated successfully', $advertisement);
    }

    // Delete an advertisement
    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'advert_id' => 'required|integer|exists:advertisements,id'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $advertisement = AdvertisementModel::findOrFail($request->input('advert_id'));

        if ($advertisement->featured_images) {
            $images = json_decode($advertisement->featured_images, true);
            if (!empty($images)) {
                foreach ($images as $imagePath) {
                    Storage::disk('public')->delete($imagePath);
                }
            }
        }

        $advertisement->delete();

        return $this->successResponse('Advertisement deleted successfully');
    }

    // Validation rules
    protected function rules($isUpdate = false)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'excerpt' => 'required|string|max:120',
            'content' => 'required|string|max:20000',
            'destination' => 'required|string|max:255',
            'fee' => 'required|numeric|min:0',
            'featured_images.*' => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'category_id' => 'required|exists:advert_categories,id'
        ];

        if ($isUpdate) {
            foreach ($rules as $key => $rule) {
                $rules[$key] = 'sometimes|' . $rule;
            }
            $rules['advert_id'] = 'required|integer|exists:advertisements,id';
        }

        return $rules;
    }

    // Save images to the file system
    protected function saveImages(Request $request)
    {
        $imagePaths = [];
        if ($request->hasFile('featured_images')) {
            foreach ($request->file('featured_images') as $image) {
                $imagePaths[] = $image->store('featured_images', 'public');
            }
        }
        return $imagePaths;
    }

    // Handle validation error responses
    protected function validationErrorResponse($validator)
    {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    // Standard success response
    protected function successResponse($message, $data = null, $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    // Handle enquiry submission
    public function enquiry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'Fname' => 'required|string|max:50',
            'Lname' => 'required|string|max:50',
            'email' => 'required|email',
            'phone' => ['required', 'regex:/^[0-9]{10,15}$/'],
            'travel_date' => 'required|date',
            'return_date' => 'required|date',
            'advert_id' => 'required|exists:advertisements,id',
            'referer' => 'required|in:' . env('FRONTEND_SOURCE')
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();
        unset($validatedData['referer']);

        Enquiries::create($validatedData);

        $emailSent = $this->sendEmail($validatedData);

        return $this->successResponse(
            'Thank you for your interest. We will contact you shortly' . ($emailSent ? '' : ' (email notification failed)'),
            null,
            201
        );
    }

    // Send email notification
    private function sendEmail($data)
    {
        
        $advert = AdvertisementModel::where('id', $data['advert_id'])->first();
        $data['title'] = $advert['title'];
        $data['reciever'] ='Elizabeth';
    

       try {
            Mail::to('ffsdtravels@gmail.com')->send(new EnquiryMail($data));
        
        } catch (\Exception $e) {
            \Log::error('Error sending email: ' . $e->getMessage());
            return false;
        }
    }
public function getEnquiries() {
    $data = Enquiries::join('advertisements', 'advertisements.id', '=', 'enquiries.advert_id')
                        ->select('advertisements.title', 'advertisements.excerpt',
                        'advertisements.destination as advert_destination',
                        'enquiries.Fname as user_first_name',
                        'enquiries.Lname as user_last_name',
                        'enquiries.email as user_email',
                        'enquiries.phone as user_phone', 
                        'enquiries.travel_date as user_travel_date',
                        'enquiries.return_date as user_return_date')
                        ->orderBy('enquiries.id', 'DESC')->paginate(10);
                         return response()->json($data);
                        // Corrected 'Order' to 'orderBy
                        }




    private function SendViaEmail($advertisementData)
    {
        $receiver = $this->getEmails();
        
        foreach( $receiver as $rec){
           $advertisementData['reciever']=$rec['Name']; 
         Mail::to($rec['emailAddress'])->send(new AdvertisementMail($advertisementData));
        
            
        }
        
    
    }

    private function getEmails()
    {
        $filePath = 'receiver_payment.json';

        // Check if the file exists
        if (!Storage::exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Advertisements file not found.',
            ], 404);
        }

        $fileContents = Storage::get($filePath);

        // Decode the JSON data to an array
        $advertisements = json_decode($fileContents, true);
        if (empty($advertisements)) {
            return response()->json([
                'success' => false,
                'message' => 'No advertisements found in the file.',
            ], 404);
        }

        return $advertisements;
    }
}

