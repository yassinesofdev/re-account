<x-mail::message>
# Verify your email

Hi {{ $user->name }},

Thanks for creating an RE-Account. Please confirm your email address to finish setting up your account.

<x-mail::button :url="$verifyUrl">
Verify Email Address
</x-mail::button>

This link expires in 1 hour. If you didn't create this account, you can safely ignore this email.

Thanks,<br>
RE-Account
</x-mail::message>
