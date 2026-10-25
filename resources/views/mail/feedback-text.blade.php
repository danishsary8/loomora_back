{{-- Plain-text mail: output raw, HTML escaping would garble the message. --}}
{!! __('account.feedback_mail_from') !!}: {!! $feedback->user ? $feedback->user->name.' <'.$feedback->user->email.'> ('.$feedback->user->id.')' : __('account.feedback_mail_guest') !!}
{!! __('account.feedback_mail_sent_at') !!}: {!! $feedback->created_at?->toIso8601ZuluString() !!}
{!! __('account.feedback_mail_id') !!}: {!! $feedback->id !!}

{!! $feedback->message !!}
