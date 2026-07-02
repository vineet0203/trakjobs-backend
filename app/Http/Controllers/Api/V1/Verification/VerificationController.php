<?php

namespace App\Http\Controllers\Api\V1\Verification;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Verification\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VerificationController extends BaseController
{
    public function __construct(
        private VerificationService $verificationService
    ) {}

    /**
     * Resolve the currently logged in user model from request or auth guard.
     */
    private function resolveUser(Request $request)
    {
        return $request->attributes->get('auth_user') ?? auth()->user();
    }

    /**
     * Get active progress state of verification wizard
     */
    public function getProgress(Request $request)
    {
        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return $this->unauthorizedResponse('User session not found.');
            }
            
            $profile = $this->verificationService->getOrCreateProfile($user);

            return $this->successResponse([
                'current_step' => $profile->current_step,
                'status' => $profile->status,
                'verification_data' => $profile->verification_data ?? new \stdClass(),
                'document_type' => $profile->document_type,
                'has_document' => !empty($profile->document_path),
            ], 'Verification progress retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Save progress fields for active wizard step
     */
    public function saveProgress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'step' => ['required', 'integer', 'between:1,6'],
            'data' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return $this->unauthorizedResponse('User session not found.');
            }

            $profile = $this->verificationService->saveProgress(
                $user,
                $request->input('step'),
                $request->input('data')
            );

            return $this->successResponse([
                'current_step' => $profile->current_step,
                'status' => $profile->status,
                'verification_data' => $profile->verification_data,
            ], 'Verification progress saved successfully.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Upload government identification document to private disk
     */
    public function uploadDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:pdf,jpeg,png', 'max:5120'], // Max 5MB
            'document_type' => ['required', 'string', 'in:passport,driver_license,national_id'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return $this->unauthorizedResponse('User session not found.');
            }

            $profile = $this->verificationService->uploadDocument(
                $user,
                $request->file('file'),
                $request->input('document_type')
            );

            return $this->successResponse([
                'document_type' => $profile->document_type,
                'has_document' => true,
            ], 'Verification document uploaded successfully.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * View/download uploaded private identification document
     */
    public function viewDocument(Request $request)
    {
        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return $this->unauthorizedResponse('User session not found.');
            }

            $profile = $user->verificationProfile;

            if (!$profile || !$profile->document_path) {
                return $this->notFoundResponse('Verification document not found.');
            }

            if (!Storage::disk('private')->exists($profile->document_path)) {
                return $this->notFoundResponse('File does not exist on storage.');
            }

            return Storage::disk('private')->response($profile->document_path);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Request a new verification OTP code via Email or WhatsApp
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:email,whatsapp'],
            'email' => ['nullable', 'string'],
            'mobile_number' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return $this->unauthorizedResponse('User session not found.');
            }

            // Verify email match if provided
            if ($request->input('type') === 'email' && $request->has('email')) {
                $inputEmail = trim(strtolower($request->input('email')));
                $regEmail = trim(strtolower($user->email));
                if ($inputEmail !== $regEmail) {
                    return $this->errorResponse('This email is not associated with this account.', 400);
                }
            }

            // Verify mobile number match if provided
            if ($request->input('type') === 'whatsapp' && $request->has('mobile_number')) {
                $inputPhone = preg_replace('/\D/', '', $request->input('mobile_number'));
                if ($user instanceof \App\Models\User) {
                    $regPhoneRaw = $user->vendor->mobile_number ?? $user->vendor->phone ?? '';
                } else {
                    $regPhoneRaw = $user->mobile_number ?? $user->phone ?? '';
                }
                $regPhone = preg_replace('/\D/', '', $regPhoneRaw);

                $inputLast10 = substr($inputPhone, -10);
                $regLast10 = substr($regPhone, -10);

                if ($inputLast10 !== $regLast10 || empty($regLast10)) {
                    return $this->errorResponse('This mobile number is not associated with this account.', 400);
                }
            }

            $this->verificationService->sendOtp($user, $request->input('type'));

            return $this->successResponse(null, 'Verification code sent successfully.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Verify the OTP code
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'size:6'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return $this->unauthorizedResponse('User session not found.');
            }

            $this->verificationService->verifyOtp($user, $request->input('code'));

            return $this->successResponse(null, 'Code verified successfully.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
