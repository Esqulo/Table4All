<x-mail::message>
# {{ __('mail.admin_invitation.heading') }}

{{ __('mail.admin_invitation.intro', ['inviter' => $inviterName, 'app' => config('app.name')]) }}

{{ __('mail.admin_invitation.cta_intro') }}

<x-mail::button :url="$acceptUrl">
{{ __('mail.admin_invitation.cta') }}
</x-mail::button>

{{ __('mail.admin_invitation.expires', ['hours' => $hours]) }}

{{ __('mail.admin_invitation.ignore') }}

{!! nl2br(e(__('mail.admin_invitation.signature', ['app' => config('app.name')]))) !!}
</x-mail::message>
