<x-mail::message>
# {{ __('mail.login.title') }}

{{ __('mail.login.intro') }}

<x-mail::button :url="$url">
{{ __('mail.login.action') }}
</x-mail::button>

{{ __('mail.login.expires', ['minutes' => $ttlMinutes]) }}

{{ __('mail.login.ignore') }}
</x-mail::message>
