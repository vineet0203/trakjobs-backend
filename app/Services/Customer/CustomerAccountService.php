<?php

namespace App\Services\Customer;

use App\Exceptions\CrossRoleEmailConflictException;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tymon\JWTAuth\Facades\JWTAuth;

class CustomerAccountService
{
    private const SETUP_TOKEN_EXPIRY_MINUTES = 60;

    public function createCustomer(array $data): array
    {
        if (DB::table('employees')->where('email', $data['email'])->exists()) {
            throw new CrossRoleEmailConflictException(
                'This email is already registered as an Employee.',
                'employee'
            );
        }

        $customer = Customer::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => null,
            'role' => 'customer',
            'status' => $data['status'] ?? 'active',
        ]);

        return $this->sendSetupLink($customer);
    }

    public function resendSetupLink(Customer $customer): array
    {
        if (!empty($customer->password)) {
            throw new HttpException(400, 'Customer password is already set.');
        }

        return $this->sendSetupLink($customer);
    }

    private function sendSetupLink(Customer $customer): array
    {

        $plainToken = Str::random(64);

        DB::table('customer_password_resets')->updateOrInsert(
            ['email' => $customer->email],
            [
                'token' => Hash::make($plainToken),
                'expires_at' => now()->addHours(48),
                'created_at' => now(),
            ]
        );

        $setupLink = $this->buildSetPasswordLink($plainToken, $customer->email);

        $emailSent = true;
        $mailError = null;

        try {
            Mail::send('emails.reset_password', [
                'name' => $customer->name ?: 'Customer',
                'resetUrl' => $setupLink,
            ], function ($message) use ($customer) {
                $message->to($customer->email)
                    ->subject('Set your password - ' . config('app.name', 'TrakJobs'));
            });

            Log::info('Customer first-time password setup link sent.', [
                'customer_id' => $customer->id,
                'email' => $customer->email,
            ]);
        } catch (\Throwable $exception) {
            $emailSent = false;
            $mailError = $exception->getMessage();

            Log::error('Customer setup email failed to send.', [
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'mailer' => config('mail.default'),
                'mail_host' => config('mail.mailers.smtp.host'),
                'mail_port' => config('mail.mailers.smtp.port'),
                'error' => $mailError,
            ]);
        }

        return [
            'customer' => $customer,
            'email_sent' => $emailSent,
            'mail_error' => $mailError,
            'expires_in_minutes' => self::SETUP_TOKEN_EXPIRY_MINUTES,
        ];
    }

    public function setPassword(string $email, string $token, string $password): void
    {
        $tokenRow = DB::table('customer_password_resets')
            ->where('email', $email)
            ->first();

        if (!$tokenRow) {
            throw new HttpException(400, 'Invalid or expired setup token.');
        }

        $createdAt = Carbon::parse($tokenRow->created_at);
        if ($createdAt->lt(now()->subMinutes(self::SETUP_TOKEN_EXPIRY_MINUTES))) {
            DB::table('customer_password_resets')->where('email', $email)->delete();
            throw new HttpException(400, 'Setup token has expired.');
        }

        if (!Hash::check($token, $tokenRow->token)) {
            throw new HttpException(400, 'Invalid or expired setup token.');
        }

        $customer = Customer::where('email', $email)->first();
        if (!$customer) {
            DB::table('customer_password_resets')->where('email', $email)->delete();
            throw new HttpException(404, 'Customer not found.');
        }

        $customer->password = bcrypt($password);
        $customer->email_verified_at = now();
        $customer->save();

        DB::table('customer_password_resets')->where('email', $email)->delete();
    }

    public function login(string $email, string $password): array
    {
        $customer = Customer::where('email', $email)->first();

        if (!$customer) {
            throw new HttpException(401, 'Invalid email or password.');
        }

        if (empty($customer->password)) {
            throw new HttpException(403, 'Please set your password first');
        }

        if (!Hash::check($password, (string) $customer->password)) {
            throw new HttpException(401, 'Invalid email or password.');
        }

        if ($customer->role !== 'customer') {
            throw new HttpException(403, 'Invalid account role for customer login.');
        }

        if ($customer->status !== 'active') {
            throw new HttpException(403, 'Customer account is inactive.');
        }

        $token = JWTAuth::claims([
            'scope' => 'customer',
            'role' => $customer->role,
        ])->fromUser($customer);

        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) (config('jwt.ttl') ? config('jwt.ttl') * 60 : 3600),
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'role' => $customer->role,
                'verification_status' => $customer->verification_status,
                'status' => $customer->status,
            ],
        ];
    }

    private function buildSetPasswordLink(string $token, string $email): string
    {
        $frontendUrl = rtrim((string) config('app.customer_frontend_url', 'https://customer.trakjobs.com'), '/');

        $parsedHost = parse_url($frontendUrl, PHP_URL_HOST);
        $isLocalHost = in_array($parsedHost, ['localhost', '127.0.0.1'], true);

        // Keep localhost over http for local development; enforce https for non-local environments.
        $baseUrl = $isLocalHost
            ? $frontendUrl
            : (preg_replace('/^http:\/\//i', 'https://', $frontendUrl) ?: 'https://customer.trakjobs.com');

        return $baseUrl
            . '/set-password?email=' . urlencode($email)
            . '&token=' . urlencode($token);
    }
}