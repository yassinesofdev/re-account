<?php

namespace App\Services;

use App\Models\OrgMember;
use App\Models\User;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

class TokenService
{
    private string $secret;

    private string $algo = 'HS256';

    public function __construct()
    {
        $this->secret = (string) config('jwt.secret');
    }

    /**
     * Issue the short-lived product handoff token.
     */
    public function issueHandoffToken(User $user, string $productSlug, ?OrgMember $membership = null): string
    {
        $now = time();

        $payload = [
            'iat' => $now,
            'exp' => $now + (int) config('jwt.ttl', 900),
            // Unique id: verify-token accepts each handoff token once (consumeHandoffToken).
            'jti' => (string) Str::uuid(),
            'purpose' => 'handoff',
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'product' => $productSlug,
            'context' => $membership ? 'org' : 'personal',
            'org_id' => $membership?->org_id,
            'org_name' => $membership?->organization?->name,
            // The org's sub-domain: products move an org sign-in to <org_slug>.<domain>.
            'org_slug' => $membership?->organization?->slug,
            'org_role' => $membership?->role,
        ];

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    /**
     * Mark a decoded handoff token as used. Returns false when it was already
     * used (or carries no `jti`). Atomic across processes: the insert relies on
     * the `jti` primary key (INSERT … ON CONFLICT DO NOTHING / INSERT OR IGNORE).
     * Expired rows are pruned on the way in.
     *
     * @param  array<string, mixed>  $payload
     */
    public function consumeHandoffToken(array $payload): bool
    {
        $jti = $payload['jti'] ?? null;

        if (! is_string($jti) || $jti === '' || strlen($jti) > 64) {
            return false;
        }

        $now = now();

        DB::table('consumed_handoff_tokens')->where('expires_at', '<', $now)->delete();

        return DB::table('consumed_handoff_tokens')->insertOrIgnore([
            'jti' => $jti,
            'expires_at' => Carbon::createFromTimestamp((int) ($payload['exp'] ?? $now->timestamp)),
            'consumed_at' => $now,
        ]) === 1;
    }

    /**
     * Issue a short-lived token used to verify a user's email address.
     */
    public function issueEmailVerificationToken(User $user): string
    {
        $now = time();

        return JWT::encode([
            'iat' => $now,
            'exp' => $now + 3600,
            'purpose' => 'email_verification',
            'user_id' => $user->id,
        ], $this->secret, $this->algo);
    }

    /**
     * Issue a short-lived token carrying arbitrary claims (e.g. a handoff session).
     *
     * @param  array<string, mixed>  $claims
     */
    public function issueGenericToken(array $claims, int $ttl = 900): string
    {
        $now = time();

        return JWT::encode([
            'iat' => $now,
            'exp' => $now + $ttl,
            ...$claims,
        ], $this->secret, $this->algo);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));

            return (array) $decoded;
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }
}
