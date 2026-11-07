<x-mail::message>
# You've been invited

{{ $inviter->name }} has invited you to join **{{ $organization->name }}** on RE-Account as a **{{ $invite->role }}**.

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

This invitation expires on {{ $invite->expires_at->format('M j, Y') }}. If you weren't expecting this, you can safely ignore this email.

Thanks,<br>
RE-Account
</x-mail::message>
