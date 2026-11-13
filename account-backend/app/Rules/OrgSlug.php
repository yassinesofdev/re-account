<?php

namespace App\Rules;

use App\Support\OrgSubdomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A new or changed org slug: DNS-safe subdomain label, not reserved.
 */
class OrgSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! OrgSubdomain::isDnsSafe($value)) {
            $fail('The slug may only contain lowercase letters, numbers and hyphens, must start and end with a letter or number, and be at most 63 characters.');

            return;
        }

        if (OrgSubdomain::isReserved($value)) {
            $fail("\"{$value}\" is reserved. Please choose a different slug.");
        }
    }
}
