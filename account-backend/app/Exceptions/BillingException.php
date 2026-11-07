<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

/**
 * A billing error with a machine-readable `code`, rendered as
 * `{message, code, ...extra}` (see bootstrap/app.php).
 */
class BillingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function planNotPurchasable(): self
    {
        return new self('This plan cannot be purchased yet.', 'plan_not_purchasable', 409);
    }

    /**
     * The target seat count (a plan's cap or the requested quantity) is below
     * the seats in use (assigned members + pending invites).
     *
     * @param  array{assigned?: int, pending_invites?: int}  $usage
     */
    public static function seatsOverLimit(int $seatsUsed, int $seatLimit, array $usage = []): self
    {
        $detail = isset($usage['assigned'])
            ? " ({$usage['assigned']} assigned, {$usage['pending_invites']} pending invites)"
            : '';

        return new self(
            "You have {$seatsUsed} seats in use{$detail}, but asked for {$seatLimit}. Turn off product access or revoke invites first.",
            'seats_over_limit',
            409,
            ['seats_used' => $seatsUsed, 'seat_limit' => $seatLimit, ...$usage],
        );
    }

    public static function billingPeriodMismatch(string $period): self
    {
        return new self(
            "Your organization is billed per {$period}. All products share one invoice, so choose a plan billed per {$period}.",
            'billing_period_mismatch',
            409,
            ['billing_period' => $period],
        );
    }

    public static function subscriptionEnding(?string $endsAt): self
    {
        return new self(
            'Your organization\'s subscription is set to end'.($endsAt ? " on {$endsAt}" : '').'. Keep your current plan first, then add products.',
            'subscription_ending',
            409,
        );
    }

    public static function seatChangeScheduled(): self
    {
        return new self(
            'A seat reduction is scheduled. Keep your current seats first, then change plans.',
            'seat_change_scheduled',
            409,
        );
    }

    public static function planEnding(): self
    {
        return new self(
            'This plan is set to end. Keep it first, then switch plans.',
            'plan_ending',
            409,
        );
    }

    public static function billingIssue(): self
    {
        return new self('Your last payment failed. Update your payment method before changing plans.', 'billing_issue', 409);
    }

    public static function paymentFailed(?string $detail = null): self
    {
        return new self(
            'The payment for this plan change failed'.($detail ? ": {$detail}" : '.'),
            'payment_failed',
            402,
        );
    }

    public static function noBillingAccount(): self
    {
        return new self('There is no billing account yet.', 'no_billing_account', 409);
    }

    public static function unavailable(): self
    {
        return new self('Billing is not available right now.', 'billing_unavailable', 503);
    }

    public static function stripeError(?ApiErrorException $e = null): self
    {
        return new self('The payment provider returned an error. Please try again.', 'stripe_error', 502);
    }

    public static function planHasPaidSubscribers(): self
    {
        return new self('This plan has paid subscribers and cannot be deleted. Deactivate it instead.', 'plan_has_paid_subscribers', 409);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$this->extra,
        ], $this->status);
    }
}
