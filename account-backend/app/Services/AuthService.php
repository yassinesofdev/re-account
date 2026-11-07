<?php

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Register a new user. When $product is given (the user is registering
     * through that product's sign-in flow), auto-attach its free personal
     * plan if it has one. A direct RE-Account registration has no product
     * context, so nothing is attached until the user picks one later.
     */
    public function register(array $data, ?Product $product = null): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        if ($product) {
            $plan = $this->subscriptions->defaultPlanFor($product, 'personal');

            if ($plan) {
                $this->subscriptions->attachPlan($user, $plan);
            }
        }

        $this->sendVerificationEmail($user);

        return $user;
    }

    public function sendVerificationEmail(User $user): void
    {
        $token = $this->tokens->issueEmailVerificationToken($user);

        Mail::to($user->email)->send(new EmailVerificationMail($user, $token));
    }

    public function verifyEmail(string $token): bool
    {
        $payload = $this->tokens->decode($token);

        if (! $payload || ($payload['purpose'] ?? null) !== 'email_verification') {
            return false;
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return false;
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return true;
    }

    public function sendPasswordResetLink(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        $plainToken = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        Mail::to($email)->send(new PasswordResetMail($user, $plainToken));
    }

    public function resetPassword(string $email, string $token, string $password): bool
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row || ! Hash::check($token, $row->token)) {
            return false;
        }

        if (now()->diffInMinutes($row->created_at) > 60) {
            return false;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            return false;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return true;
    }
}
