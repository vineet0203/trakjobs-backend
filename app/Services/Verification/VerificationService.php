<?php

namespace App\Services\Verification;

use App\Models\VerificationProfile;
use App\Models\VerificationOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class VerificationService
{
    /**
     * Get or create verification profile for the authenticatable user
     */
    public function getOrCreateProfile($user): VerificationProfile
    {
        $profile = $user->verificationProfile;

        if (!$profile) {
            $profile = VerificationProfile::create([
                'authenticatable_type' => get_class($user),
                'authenticatable_id' => $user->id,
                'status' => 'pending',
                'current_step' => 1,
                'verification_data' => [],
            ]);
        }

        return $profile;
    }

    /**
     * Save progress for the verification flow step
     */
    public function saveProgress($user, int $step, array $stepData): VerificationProfile
    {
        $profile = $this->getOrCreateProfile($user);

        if ($step === 6) {
            if (!$this->hasVerifiedOtp($user, 'email')) {
                throw ValidationException::withMessages([
                    'email' => 'Please verify your email address before submitting.',
                ]);
            }

            if (config('verification.phone_otp_required') && !$this->hasVerifiedOtp($user, 'whatsapp')) {
                throw ValidationException::withMessages([
                    'phone' => 'Please verify your phone number before submitting.',
                ]);
            }
        }

        // Merge new step data into existing verification_data JSON
        $existingData = $profile->verification_data ?? [];
        $mergedData = array_merge($existingData, $stepData);

        $profile->verification_data = $mergedData;
        $profile->current_step = max($profile->current_step, $step);

        if ($profile->status === 'pending') {
            $profile->status = 'in_progress';
        }

        // Automatic Verification Check on Step 6 submission
        if ($step === 6) {
            $profile->status = 'verified';
            $profile->submitted_at = now();
            $profile->verified_at = now();

            // Set user/customer/employee core verification_status to 'verified'
            $user->update(['verification_status' => 'verified']);
        }

        $profile->save();
        return $profile;
    }

    /**
     * Upload identification document to the private storage disk
     */
    public function uploadDocument($user, UploadedFile $file, string $documentType): VerificationProfile
    {
        $profile = $this->getOrCreateProfile($user);

        // Store file securely in local 'private' disk
        $path = $file->store('verification_documents', 'private');

        // Delete old document if it exists to clean storage
        if ($profile->document_path) {
            Storage::disk('private')->delete($profile->document_path);
        }

        $profile->update([
            'document_type' => $documentType,
            'document_path' => $path,
        ]);

        return $profile;
    }

    /**
     * Send One-Time Passcode (OTP) via Email or Mock WhatsApp SMS
     */
    public function sendOtp($user, string $type): bool
    {
        $destination = $type === 'email'
            ? strtolower(trim($user->email))
            : $this->getPhoneDestination($user);

        if ($type === 'whatsapp') {
            if (!$destination) {
                throw new \Exception('No mobile phone number associated with this account.');
            }
        }

        // Rate limiting check (max 1 OTP per contact every 60 seconds)
        $recent = VerificationOtp::where('authenticatable_type', get_class($user))
            ->where('authenticatable_id', $user->id)
            ->where('contact_type', $type)
            ->where('contact_destination', $destination)
            ->where('created_at', '>', now()->subMinute())
            ->first();

        if ($recent) {
            throw new \Exception('Please wait 60 seconds before requesting a new code.');
        }

        // Generate 6-digit random code
        $code = (string) random_int(100000, 999999);
        $hash = Hash::make($code);

        VerificationOtp::create([
            'contact_type' => $type,
            'contact_destination' => $destination,
            'authenticatable_type' => get_class($user),
            'authenticatable_id' => $user->id,
            'otp_hash' => $hash,
            'expires_at' => now()->addMinutes(10),
        ]);

        if ($type === 'email') {
            try {
                Mail::send('emails.verification_code', [
                    'name' => $user->name ?? $user->first_name ?? 'User',
                    'code' => $code,
                ], function ($message) use ($destination) {
                    $message->to($destination)
                        ->subject('Your TrakJobs Account Verification Code');
                });
                Log::info("Verification OTP sent via Email to: {$destination}. Code: {$code}");
            } catch (\Throwable $e) {
                Log::error("Failed to send verification email to {$destination}: " . $e->getMessage());
                throw new \Exception('Failed to send verification email.');
            }
        } else {
            // Live Twilio WhatsApp Integration
            $sid = env('TWILIO_SID');
            $token = env('TWILIO_AUTH_TOKEN');
            $from = env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886');

            if (empty($sid) || empty($token)) {
                Log::warning("Twilio credentials missing in .env. Logging WhatsApp OTP to: {$destination}. Code: {$code}");
                return true;
            }

            try {
                $twilio = new \Twilio\Rest\Client($sid, $token);
                
                $phone = $destination;
                if (str_starts_with($phone, 'whatsapp:')) {
                    $phone = str_replace('whatsapp:', '', $phone);
                }
                if (!str_starts_with($phone, '+')) {
                    if (strlen($phone) === 10) {
                        $phone = '+91' . $phone;
                    } elseif (str_starts_with($phone, '91') && strlen($phone) === 12) {
                        $phone = '+' . $phone;
                    }
                }
                $to = 'whatsapp:' . $phone;

                $twilio->messages->create($to, [
                    'from' => $from,
                    'body' => "Your TrakJobs verification code is: {$code}"
                ]);
                Log::info("Verification OTP sent via Twilio WhatsApp to: {$destination}. Code: {$code}");
            } catch (\Throwable $e) {
                Log::error("Failed to send WhatsApp message via Twilio to {$destination}: " . $e->getMessage());
                throw new \Exception('Failed to send WhatsApp verification message.');
            }
        }

        return true;
    }

    /**
     * Verify the One-Time Passcode (OTP)
     */
    public function verifyOtp($user, string $code, string $type): bool
    {
        $destination = $type === 'email'
            ? strtolower(trim($user->email))
            : $this->getPhoneDestination($user);

        $otp = VerificationOtp::where('authenticatable_type', get_class($user))
        ->where('authenticatable_id', $user->id)
        ->where('contact_type', $type)
        ->where('contact_destination', $destination)
        ->whereNull('verified_at')
        ->where('expires_at', '>', now())
        ->orderBy('created_at', 'desc')
        ->first();

        if (!$otp) {
            throw new \Exception('Verification code is invalid or expired.');
        }

        if ($otp->attempts >= 3) {
            throw new \Exception('Too many failed verification attempts. Please request a new code.');
        }

        if (!Hash::check($code, $otp->otp_hash)) {
            $otp->increment('attempts');
            throw new \Exception('Incorrect verification code.');
        }

        $otp->update(['verified_at' => now()]);

        return true;
    }

    private function hasVerifiedOtp($user, string $type): bool
    {
        $destination = $type === 'email'
            ? strtolower(trim($user->email))
            : $this->getPhoneDestination($user);

        if (!$destination) {
            return false;
        }

        return VerificationOtp::where('authenticatable_type', get_class($user))
            ->where('authenticatable_id', $user->id)
            ->where('contact_type', $type)
            ->where('contact_destination', $destination)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function getPhoneDestination($user): ?string
    {
        if ($user instanceof \App\Models\User) {
            $phone = $user->vendor?->mobile_number ?? $user->vendor?->phone;
        } elseif ($user instanceof \App\Models\Employee) {
            $phone = $user->mobile_number ?? $user->phone;
        } else {
            $phone = $user->mobile_number ?? $user->phone;
        }

        if (!$phone) {
            return null;
        }

        $digits = ltrim(preg_replace('/\D/', '', $phone), '0');
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }

        return '+' . $digits;
    }
}
