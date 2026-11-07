<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrgMember;
use App\Models\Product;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OrgService;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
        private readonly OrgService $orgs,
    ) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'handoff_session' => ['nullable', 'string'],
            'org_id' => ['nullable', 'string', 'uuid'],
        ]);

        $product = $this->resolveProductFromHandoffSession($data['handoff_session'] ?? null);

        // A new account is never a super admin: don't create it just to refuse it.
        if ($product?->super_admin_only) {
            return $this->notSuperAdmin($product);
        }

        $user = $this->auth->register($data, $product);

        Auth::login($user);
        $request->session()->regenerate();

        return $this->respondWithHandoffOrUser($user, $data['handoff_session'] ?? null, $data['org_id'] ?? null);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'handoff_session' => ['nullable', 'string'],
            'org_id' => ['nullable', 'string', 'uuid'],
        ]);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        return $this->respondWithHandoffOrUser($user, $data['handoff_session'] ?? null, $data['org_id'] ?? null);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function verifyEmail(string $token)
    {
        if (! $this->auth->verifyEmail($token)) {
            return response()->json(['message' => 'This verification link is invalid or has expired.'], 422);
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $this->auth->sendVerificationEmail($user);

        return response()->json(['message' => 'Verification email sent.']);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $this->auth->sendPasswordResetLink($data['email']);

        return response()->json(['message' => 'If an account exists for that email, a reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $this->auth->resetPassword($data['email'], $data['token'], $data['password'])) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function handoff(Request $request)
    {
        $data = $request->validate([
            'product' => ['required', 'string'],
            'redirect' => ['required', 'url'],
        ]);

        $product = Product::where('slug', $data['product'])->where('is_active', true)->first();

        if (! $product) {
            return response()->json(['message' => 'Unknown or inactive product.'], 404);
        }

        if (! str_starts_with($data['redirect'], rtrim($product->redirect_url, '/'))) {
            return response()->json(['message' => 'Redirect URL is not registered for this product.'], 422);
        }

        $handoffSession = $this->tokens->issueGenericToken([
            'purpose' => 'handoff_session',
            'product' => $product->slug,
            'redirect' => $data['redirect'],
        ]);

        return response()->json([
            'handoff_session' => $handoffSession,
            'product' => [
                'name' => $product->name,
                'slug' => $product->slug,
                'super_admin_only' => $product->super_admin_only,
            ],
        ]);
    }

    public function handoffComplete(Request $request)
    {
        $data = $request->validate([
            'handoff_session' => ['required', 'string'],
            'org_id' => ['nullable', 'string', 'uuid'],
        ]);

        return $this->respondWithHandoffOrUser($request->user(), $data['handoff_session'], $data['org_id'] ?? null);
    }

    private function notSuperAdmin(Product $product)
    {
        return response()->json([
            'message' => "{$product->name} is only available to RE-Account super admins.",
            'code' => 'not_super_admin',
            'product' => ['slug' => $product->slug, 'name' => $product->name],
        ], 403);
    }

    private function resolveProductFromHandoffSession(?string $handoffSession): ?Product
    {
        if (! $handoffSession) {
            return null;
        }

        $payload = $this->tokens->decode($handoffSession);

        if (! $payload || ($payload['purpose'] ?? null) !== 'handoff_session') {
            return null;
        }

        return Product::where('slug', $payload['product'])->first();
    }

    private function respondWithHandoffOrUser(User $user, ?string $handoffSession, ?string $orgId)
    {
        if (! $handoffSession) {
            return response()->json(['user' => $user]);
        }

        $payload = $this->tokens->decode($handoffSession);

        if (! $payload || ($payload['purpose'] ?? null) !== 'handoff_session') {
            return response()->json(['message' => 'Invalid or expired handoff session.'], 422);
        }

        $product = Product::where('slug', $payload['product'])->first();

        // Super-admin-only products (re-data): no plans/seats, just the flag.
        if ($product?->super_admin_only && ! $user->is_super_admin) {
            return $this->notSuperAdmin($product);
        }

        $membership = null;

        if ($orgId) {
            $membership = OrgMember::with('organization')->where('org_id', $orgId)->where('user_id', $user->id)->first();
        }

        // Org context needs a seat for this product in that org. Personal
        // context (and an org the user isn't in, which falls back) is open.
        if ($membership && ! $product?->super_admin_only) {
            if (! $product || ! $this->orgs->hasProductAccess($membership->org_id, $user->id, $product)) {
                $productName = $product->name ?? $payload['product'];
                $orgName = $membership->organization->name;

                return response()->json([
                    'message' => "You don't have access to {$productName} in {$orgName}. Ask your organization admin.",
                    'code' => 'no_product_access',
                    'product' => ['slug' => $payload['product'], 'name' => $productName],
                    'organization' => ['id' => $membership->org_id, 'name' => $orgName, 'slug' => $membership->organization->slug],
                ], 403);
            }
        }

        $token = $this->tokens->issueHandoffToken($user, $payload['product'], $membership);

        $separator = str_contains($payload['redirect'], '?') ? '&' : '?';

        return response()->json([
            'redirect_url' => $payload['redirect'].$separator.'token='.$token,
        ]);
    }
}
