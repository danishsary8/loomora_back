<?php

namespace App\Http\Requests\Focus;

use App\Http\Requests\Todo\TodoRules;
use App\Models\FocusSession;
use App\Support\FocusSessionPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape and internal consistency of one finished Focus session (B-043).
 * Ownership of linked items is checked by FocusSessionService, after the replay check.
 */
class StoreFocusSessionRequest extends FormRequest
{
    private ?FocusSessionPayload $payload = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $timestamp = ['required', ...TodoRules::dateTimeRules()];

        return [
            'id' => ['required', 'uuid'],
            'mode' => ['required', 'string', Rule::in(FocusSession::MODES)],
            'end_reason' => ['required', 'string', Rule::in(FocusSession::END_REASONS)],
            'started_at' => $timestamp,
            'ended_at' => $timestamp,
            'planned_focus_ms' => ['nullable', 'integer', 'min:1', 'required_if:mode,'.FocusSession::MODE_COUNTDOWN, 'prohibited_if:mode,'.FocusSession::MODE_STOPWATCH],
            'todo_id' => ['nullable', 'uuid', 'prohibits:schedule_item_id'],
            'schedule_item_id' => ['nullable', 'uuid'],
            'label' => ['nullable', 'string', 'max:'.FocusSession::MAX_LABEL_LENGTH],
            'segments' => ['required', 'array', 'list', 'min:1', 'max:'.FocusSession::MAX_SEGMENTS],
            'segments.*' => ['array:type,started_at,ended_at,active_ms'],
            'segments.*.type' => ['required', 'string', Rule::in(FocusSession::SEGMENT_TYPES)],
            'segments.*.started_at' => $timestamp,
            'segments.*.ended_at' => $timestamp,
            'segments.*.active_ms' => ['required', 'integer', 'min:0'],
            'laps' => ['nullable', 'array', 'list', 'max:'.FocusSession::MAX_LAPS, 'prohibited_if:mode,'.FocusSession::MODE_COUNTDOWN],
            'laps.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $payload = $this->payload();
                $this->checkTimes($validator, $payload);
                $this->checkSegments($validator, $payload);
                $this->checkLaps($validator, $payload);
            },
        ];
    }

    public function payload(): FocusSessionPayload
    {
        return $this->payload ??= FocusSessionPayload::fromInput($this->validator->validated());
    }

    private function checkTimes(Validator $validator, FocusSessionPayload $payload): void
    {
        if ($payload->endedMs < $payload->startedMs) {
            $validator->errors()->add('ended_at', __('focus.end_before_start'));
        }

        if ($payload->endedMs > now()->getTimestampMs() + FocusSession::MAX_CLOCK_SKEW_MS) {
            $validator->errors()->add('ended_at', __('focus.end_in_future'));
        }
    }

    private function checkSegments(Validator $validator, FocusSessionPayload $payload): void
    {
        $previousEnd = $payload->startedMs;

        foreach ($payload->segments as $index => $segment) {
            $inOrder = $segment['started_ms'] >= $previousEnd
                && $segment['ended_ms'] >= $segment['started_ms']
                && $segment['ended_ms'] <= $payload->endedMs;
            if (! $inOrder) {
                $validator->errors()->add("segments.{$index}", __('focus.segment_out_of_order'));
            }

            if ($segment['active_ms'] > $segment['ended_ms'] - $segment['started_ms']) {
                $validator->errors()->add("segments.{$index}.active_ms", __('focus.segment_active_too_long'));
            }

            if ($payload->mode === FocusSession::MODE_STOPWATCH && $segment['type'] !== FocusSession::SEGMENT_FOCUS) {
                $validator->errors()->add("segments.{$index}.type", __('focus.stopwatch_break'));
            }

            $previousEnd = max($previousEnd, $segment['ended_ms']);
        }

        if ($payload->focusMs() <= 0) {
            $validator->errors()->add('segments', __('focus.no_focus_time'));
        }
    }

    private function checkLaps(Validator $validator, FocusSessionPayload $payload): void
    {
        $previous = 0;

        foreach ($payload->laps ?? [] as $lap) {
            if ($lap <= $previous || $lap > $payload->focusMs()) {
                $validator->errors()->add('laps', __('focus.laps_out_of_order'));

                return;
            }
            $previous = $lap;
        }
    }
}
