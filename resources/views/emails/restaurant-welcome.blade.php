<x-mail::message>
# {{ __('mail.restaurant_welcome.heading') }}

{{ __('mail.restaurant_welcome.intro', ['restaurant' => $restaurantName, 'app' => config('app.name')]) }}

{{ __('mail.restaurant_welcome.cta_intro') }}

<x-mail::button :url="$passwordUrl">
{{ __('mail.restaurant_welcome.cta') }}
</x-mail::button>

{{ __('mail.restaurant_welcome.expires', ['minutes' => $minutes]) }}

{!! nl2br(e(__('mail.restaurant_welcome.signature', ['app' => config('app.name')]))) !!}
</x-mail::message>
