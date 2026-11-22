<x-mail::message>
# {{ __($group.'.title') }}

{{ __($group.'.intro') }}

<x-mail::button :url="$url">
{{ __($group.'.action') }}
</x-mail::button>

{{ __('mail.login.expires', ['minutes' => $ttlMinutes]) }}

{{ __($group.'.ignore') }}
</x-mail::message>
