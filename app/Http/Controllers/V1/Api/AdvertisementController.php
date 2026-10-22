<?php
namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Mail\AdvertisementMail;
use App\Mail\Enquiries as EnquiryMail;
use App\Models\AdvertisementModel;
use App\Models\Enquiries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdvertisementController extends Controller
{
    /**
     * Submit an advertisement request and notify configured recipients.
     *
     * This endpoint does not create an AdvertisementModel record.
     */
    public function makeAdvertisement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'fullName' => ['required', 'string', 'max:160'],
            'location' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[0-9().\s-]{7,20}$/',
            ],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $emailSent = $this->sendAdvertisementEmail(
            $validator->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Advertisement request received successfully.',
            'email_sent' => $emailSent,
        ], 201);
    }

    /**
     * List advertisements.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $perPage = (int) $request->input('per_page', 10);

        $advertisements = AdvertisementModel::with('category')
            ->paginate($perPage);

        return response()->json($advertisements);
    }

    /**
     * Show one advertisement.
     */
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'advert_id' => [
                'required',
                'integer',
                'exists:advertisements,id',
            ],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $advertisement = AdvertisementModel::with('category')
            ->findOrFail($request->integer('advert_id'));

        return response()->json($advertisement);
    }

    /**
     * Create an advertisement record.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            $this->rules()
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        unset($validated['featured_images']);

        $imagePaths = $this->saveImages($request);

        try {
            $advertisement = AdvertisementModel::create(
                array_merge($validated, [
                    'featured_images' => json_encode(
                        $imagePaths,
                        JSON_THROW_ON_ERROR
                    ),
                ])
            );
        } catch (Throwable $e) {
            $this->deleteStoredImages($imagePaths);

            Log::error('Failed to create advertisement.', [
                'exception' => get_class($e),
            ]);

            return $this->serverErrorResponse(
                'Unable to create the advertisement.'
            );
        }

        return $this->successResponse(
            'Advertisement created successfully.',
            $advertisement,
            201
        );
    }

    /**
     * Update an advertisement record.
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            $this->rules(true)
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        $advertisement = AdvertisementModel::findOrFail(
            $validated['advert_id']
        );

        unset($validated['advert_id'], $validated['featured_images']);

        $oldImagePaths = $this->decodeImagePaths(
            $advertisement->featured_images
        );

        $newImagePaths = [];

        if ($request->hasFile('featured_images')) {
            $newImagePaths = $this->saveImages($request);

            try {
                $validated['featured_images'] = json_encode(
                    $newImagePaths,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable $e) {
                $this->deleteStoredImages($newImagePaths);

                Log::error('Failed to encode advertisement images.');

                return $this->serverErrorResponse(
                    'Unable to update advertisement images.'
                );
            }
        }

        try {
            $advertisement->update($validated);
        } catch (Throwable $e) {
            $this->deleteStoredImages($newImagePaths);

            Log::error('Failed to update advertisement.', [
                'exception' => get_class($e),
            ]);

            return $this->serverErrorResponse(
                'Unable to update the advertisement.'
            );
        }

        // Delete old files only after the database update succeeds.
        if (!empty($newImagePaths)) {
            $this->deleteStoredImages($oldImagePaths);
        }

        return $this->successResponse(
            'Advertisement updated successfully.',
            $advertisement->fresh()
        );
    }

    /**
     * Delete an advertisement and its stored images.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'advert_id' => [
                'required',
                'integer',
                'exists:advertisements,id',
            ],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $advertisement = AdvertisementModel::findOrFail(
            $request->integer('advert_id')
        );

        $imagePaths = $this->decodeImagePaths(
            $advertisement->featured_images
        );

        try {
            $advertisement->delete();
        } catch (Throwable $e) {
            Log::error('Failed to delete advertisement.', [
                'exception' => get_class($e),
            ]);

            return $this->serverErrorResponse(
                'Unable to delete the advertisement.'
            );
        }

        // Database deletion has succeeded; clean up files afterwards.
        $this->deleteStoredImages($imagePaths);

        return $this->successResponse(
            'Advertisement deleted successfully.'
        );
    }

    /**
     * Validation rules for advertisement CRUD operations.
     */
    protected function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'excerpt' => [$required, 'string', 'max:120'],
            'content' => [$required, 'string', 'max:20000'],
            'destination' => [$required, 'string', 'max:255'],
            'fee' => [$required, 'numeric', 'min:0'],
            'featured_images' => [
                'sometimes',
                'array',
                'max:10',
            ],
            'featured_images.*' => [
                'image',
                'mimes:jpeg,jpg,png',
                'max:2048',
            ],
            'start_date' => [$required, 'date'],
            'end_date' => [
                $required,
                'date',
                'after:start_date',
            ],
            'category_id' => [
                $required,
                'integer',
                'exists:advert_categories,id',
            ],
            ...($isUpdate ? [
                'advert_id' => [
                    'required',
                    'integer',
                    'exists:advertisements,id',
                ],
            ] : []),
        ];
    }

    /**
     * Store validated uploaded images.
     *
     * If an upload fails, remove files already stored by this call.
     */
    protected function saveImages(Request $request): array
    {
        $imagePaths = [];

        try {
            foreach ($request->file('featured_images', []) as $image) {
                $path = $image->store(
                    'featured_images',
                    'public'
                );

                if ($path === false) {
                    throw new \RuntimeException(
                        'Image storage operation failed.'
                    );
                }

                $imagePaths[] = $path;
            }
        } catch (Throwable $e) {
            $this->deleteStoredImages($imagePaths);

            Log::error('Advertisement image upload failed.', [
                'exception' => get_class($e),
            ]);

            throw $e;
        }

        return $imagePaths;
    }

    /**
     * Decode the stored image list safely.
     */
    private function decodeImagePaths(mixed $images): array
    {
        if (is_array($images)) {
            $paths = $images;
        } elseif (is_string($images) && $images !== '') {
            $paths = json_decode($images, true);
        } else {
            return [];
        }

        if (!is_array($paths)) {
            return [];
        }

        // Restrict deletion to files inside our image directory.
        return array_values(array_filter(
            $paths,
            static fn ($path): bool =>
                is_string($path)
                && str_starts_with($path, 'featured_images/')
                && !str_contains($path, '..')
        ));
    }

    /**
     * Delete files from the public storage disk.
     */
    private function deleteStoredImages(array $paths): void
    {
        foreach ($this->decodeImagePaths($paths) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $e) {
                Log::warning('Unable to delete advertisement image.', [
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Submit an enquiry and send its email notification.
     */
    public function enquiry(Request $request): JsonResponse
    {
        $frontendSource = config('app.frontend_source');

        $rules = [
            'Fname' => ['required', 'string', 'max:50'],
            'Lname' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => [
                'required',
                'regex:/^[0-9]{10,15}$/',
            ],
            'travel_date' => ['required', 'date'],
            'return_date' => [
                'required',
                'date',
                'after_or_equal:travel_date',
            ],
            'advert_id' => [
                'required',
                'integer',
                'exists:advertisements,id',
            ],
        ];

        if (is_string($frontendSource) && $frontendSource !== '') {
            $rules['referer'] = [
                'required',
                'in:' . $frontendSource,
            ];
        } else {
            Log::error('Frontend source configuration is missing.');

            return $this->serverErrorResponse(
                'The enquiry service is temporarily unavailable.'
            );
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        unset($validated['referer']);

        try {
            $enquiry = Enquiries::create($validated);
        } catch (Throwable $e) {
            Log::error('Failed to save advertisement enquiry.', [
                'exception' => get_class($e),
            ]);

            return $this->serverErrorResponse(
                'Unable to submit your enquiry.'
            );
        }

        $emailSent = $this->sendEnquiryEmail(
            $validated
        );

        return $this->successResponse(
            'Thank you for your interest. We will contact you shortly.',
            [
                'enquiry_id' => $enquiry->getKey(),
                'email_sent' => $emailSent,
            ],
            201
        );
    }

    /**
     * Send the enquiry email.
     */
    private function sendEnquiryEmail(array $data): bool
    {
        $advertisement = AdvertisementModel::find(
            $data['advert_id']
        );

        if (!$advertisement) {
            Log::warning(
                'Enquiry email skipped because the advertisement was not found.'
            );

            return false;
        }

        $data['title'] = $advertisement->title;
        $data['reciever'] = 'Elizabeth';

        try {
            Mail::to('ffsdtravels@gmail.com')->send(
                new EnquiryMail($data)
            );

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to send enquiry email.', [
                'exception' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Return enquiries with advertisement details.
     */
    public function getEnquiries(): JsonResponse
    {
        $data = Enquiries::join(
            'advertisements',
            'advertisements.id',
            '=',
            'enquiries.advert_id'
        )
            ->select(
                'advertisements.title',
                'advertisements.excerpt',
                'advertisements.destination as advert_destination',
                'enquiries.Fname as user_first_name',
                'enquiries.Lname as user_last_name',
                'enquiries.email as user_email',
                'enquiries.phone as user_phone',
                'enquiries.travel_date as user_travel_date',
                'enquiries.return_date as user_return_date'
            )
            ->orderByDesc('enquiries.id')
            ->paginate(10);

        return response()->json($data);
    }

    /**
     * Send advertisement emails to configured recipients.
     *
     * services.amadeus.booked_recipients may be a PHP array
     * or a JSON-encoded string.
     */
    private function sendAdvertisementEmail(
        array $advertisementData
    ): bool {
        $configuredRecipients = $this->amadeusConfig(
            'booked_recipients',
            '[]'
        );

        $recipients = is_array($configuredRecipients)
            ? $configuredRecipients
            : json_decode(
                (string) $configuredRecipients,
                true
            );

        if (!is_array($recipients) || empty($recipients)) {
            Log::warning(
                'No advertisement email recipients are configured.'
            );

            return false;
        }

        $emailSent = false;

        foreach ($recipients as $recipient) {
            if (
                !is_array($recipient)
                || !is_string($recipient['email'] ?? null)
                || !filter_var(
                    $recipient['email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                Log::warning(
                    'Skipping an invalid advertisement email recipient.'
                );

                continue;
            }

            $emailData = $advertisementData;
            $emailData['reciever'] = is_string(
                $recipient['full_name'] ?? null
            ) ? $recipient['full_name'] : '';

            try {
                Mail::to($recipient['email'])->send(
                    new AdvertisementMail($emailData)
                );

                $emailSent = true;
            } catch (Throwable $e) {
                Log::error('Failed to send advertisement email.', [
                    'exception' => get_class($e),
                ]);
            }
        }

        return $emailSent;
    }

    /**
     * Read the Amadeus service configuration.
     */
    private function amadeusConfig(
        string $key,
        mixed $default = null
    ): mixed {
        return config('services.amadeus.' . $key, $default);
    }

    /**
     * Standard validation response.
     */
    protected function validationErrorResponse(
        $validator
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => 'The submitted data is invalid.',
            'errors' => $validator->errors(),
        ], 422);
    }

    /**
     * Standard success response.
     */
    protected function successResponse(
        string $message,
        mixed $data = null,
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Safe generic server error response.
     */
    private function serverErrorResponse(
        string $message
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 500);
    }
}
