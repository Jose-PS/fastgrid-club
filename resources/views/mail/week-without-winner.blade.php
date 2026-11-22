<x-mail::message>
# {{ __('mail.week.title', ['date' => $date]) }}

{{ __('mail.week.intro') }}

{{ $outcome }}

{{ __('mail.week.review') }}
</x-mail::message>
