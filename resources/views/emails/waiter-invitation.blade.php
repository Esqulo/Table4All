<x-mail::message>
# {{ __('mail.waiter_invitation.heading') }}

{{ __('mail.waiter_invitation.intro', ['restaurant' => $restaurantName, 'app' => config('app.name')]) }}

{{ __('mail.waiter_invitation.cta_intro') }}

<x-mail::button :url="$acceptUrl">
{{ __('mail.waiter_invitation.cta') }}
</x-mail::button>

{{ __('mail.waiter_invitation.expires', ['days' => 7]) }}

{{ __('mail.waiter_invitation.ignore') }}

{!! nl2br(e(__('mail.waiter_invitation.signature', ['app' => config('app.name')]))) !!}
</x-mail::message>
