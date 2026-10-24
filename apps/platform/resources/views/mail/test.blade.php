<x-mail::message>
# {{ __('It works!') }}

{{ __('This is a test email sent from :app to verify the mail server configuration.', ['app' => config('app.name')]) }}

{{ __('Mailer') }}: **{{ config('mail.default') }}**

{{ __('Regards') }},<br>
{{ config('app.name') }}
</x-mail::message>
