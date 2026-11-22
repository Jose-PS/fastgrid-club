<x-mail::message>
# {{ __('mail.social.title', ['type' => $type]) }}

{{ __('mail.social.intro') }}

<x-mail::panel>
{{ $text }}
</x-mail::panel>

<x-mail::button :url="$url">
{{ __('mail.social.action') }}
</x-mail::button>

{{ __('mail.social.review') }}
</x-mail::message>
