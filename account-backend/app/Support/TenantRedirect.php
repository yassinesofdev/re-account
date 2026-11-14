<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Tenant callback URLs: a product's `tenant_redirect_url` is a template whose
 * first host label is `{tenant}`, e.g. https://{tenant}.re-es.xyz/intelligence/auth/callback
 * (local http://{tenant}.re.localsite:3741/intelligence/auth/callback). A handoff
 * redirect matches when it starts with the template with `{tenant}` replaced by
 * an existing, non-reserved org slug; the sign-in is then bound to that org.
 */
class TenantRedirect
{
    public const PLACEHOLDER = '{tenant}';

    /** `{tenant}` exactly once, as the first label of an http(s) host. */
    public const TEMPLATE_PATTERN = '#^https?://\{tenant\}\.[a-z0-9.-]+(:\d+)?(/\S*)?$#';

    public static function isValidTemplate(?string $template): bool
    {
        return $template !== null
            && substr_count($template, self::PLACEHOLDER) === 1
            && preg_match(self::TEMPLATE_PATTERN, $template) === 1;
    }

    /** The org slug in `$redirect` when it matches the template, else null (no DB lookup). */
    public static function slugFrom(?string $template, string $redirect): ?string
    {
        if (! self::isValidTemplate($template)) {
            return null;
        }

        [$before, $after] = explode(self::PLACEHOLDER, rtrim($template, '/'), 2);
        // The slug group can't contain "." "/" ":" "@", so the host stays the template's.
        $label = '([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)';
        $pattern = '#^'.preg_quote($before, '#').$label.preg_quote($after, '#').'(?:$|[/?\#])#';

        return preg_match($pattern, $redirect, $m) === 1 ? $m[1] : null;
    }

    /** The org a tenant redirect is bound to: an existing org with an allowed (non-reserved) slug. */
    public static function organization(?string $template, string $redirect): ?Organization
    {
        $slug = self::slugFrom($template, $redirect);

        if ($slug === null || OrgSubdomain::isReserved($slug)) {
            return null;
        }

        return Organization::where('slug', $slug)->first();
    }
}
