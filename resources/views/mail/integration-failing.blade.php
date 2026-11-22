<x-mail::message>
# {{ __('mail.integration.title', ['server' => $server]) }}

{{ __('mail.integration.intro', ['type' => $typeLabel, 'count' => $failures]) }}

{{ __('mail.integration.last', ['summary' => $summary]) }}

{{ __('mail.integration.review') }}
</x-mail::message>
