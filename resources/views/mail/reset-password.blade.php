@component('mail.layout', ['title' => __('mail.reset.subject')])
    <p style="margin:0 0 12px;font-size:18px;font-weight:bold;">{{ __('mail.greeting', ['name' => $user->name]) }}</p>
    <p style="margin:0 0 20px;line-height:1.5;">{{ __('mail.reset.intro') }}</p>
    @include('mail.button', ['url' => $url, 'label' => __('mail.reset.action')])
    <p style="margin:20px 0 0;font-size:13px;line-height:1.5;color:#444444;">{{ __('mail.reset.expires', ['minutes' => $minutes]) }}</p>
    <p style="margin:8px 0 0;font-size:13px;line-height:1.5;color:#444444;">{{ __('mail.reset.outro') }}</p>
    <p style="margin:12px 0 0;font-size:12px;word-break:break-all;color:#444444;">{{ $url }}</p>
@endcomponent
