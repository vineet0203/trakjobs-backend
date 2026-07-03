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
        $destination = $user->email;
        if ($type === 'whatsapp') {
            $vendorNumber = DB::table('vendors')->where('user_id', $user->id)->value('mobile_number');
            $employeeNumber = DB::table('employees')->where('id', $user->id)->value('mobile_number');
            $destination = $user->phone ?? $user->mobile_number ?? $vendorNumber ?? $employeeNumber;
            if (!$destination) {
                throw new \Exception('No mobile phone number associated with this account.');
            }
            // Normalize to E.164 format for Twilio (India default +91)
            $destination = preg_replace('/[^0-9]/', '', $destination);
            $destination = ltrim($destination, '0');
            if (strlen($destination) === 10) {
                $destination = '91' . $destination;
            }
            $destination = '+' . $destination;
        }

        // Rate limiting check (max 1 OTP per contact every 60 seconds)
        $recent = VerificationOtp::where('contact_destination', $destination)
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
            $from = env('TWILIO_WHATSAPP_NUMBER', 'whatsapp:+14155238886');

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
    public function verifyOtp($user, string $code): bool
    {
        $email = $user->email;
        $vendorNumber = DB::table('vendors')->where('user_id', $user->id)->value('mobile_number');
        $employeeNumber = DB::table('employees')->where('id', $user->id)->value('mobile_number');
        $phone = $user->phone ?? $user->mobile_number ?? $vendorNumber ?? $employeeNumber;

        // Retrieve active OTP records matching either email or phone destination
        $otp = VerificationOtp::where(function ($query) use ($email, $phone) {
            $query->where('contact_destination', $email);
            if ($phone) {
                $query->orWhere('contact_destination', $phone);
            }
        })
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

        // Save phone_otp_verified_at attribute if it's phone validation
        if ($otp->contact_type === 'whatsapp') {
            $user->update(['phone_otp_verified_at' => now()]);
        }

        return true;
    }
}
