<x-mail::message>
# Reset your password

Hi {{ $user->name }},

We received a request to reset the password for your RE-Account. Click below to choose a new one.

<x-mail::button :url="$resetUrl">
Reset Password
</x-mail::button>

This link expires in 60 minutes. If you didn't request a password reset, you can safely ignore this email.

Thanks,<br>
RE-Account
</x-mail::message>
