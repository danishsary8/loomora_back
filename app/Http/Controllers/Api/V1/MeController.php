<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteMeRequest;
use App\Http\Requests\Account\SurveyRequest;
use App\Http\Requests\Account\UpdateMeRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Response;

class MeController extends Controller
{
    public function update(UpdateMeRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }

    /** Skippable: an empty submit still marks the survey as done. */
    public function survey(SurveyRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill([
            'goal' => $request->validated('goal'),
            'heard_from' => $request->validated('heard_from'),
        ]);
        $user->forceFill(['survey_done' => true])->save();

        return new UserResource($user);
    }

    /** Confirm, then delete everything immediately (docs/07 S14). */
    public function destroy(DeleteMeRequest $request): Response
    {
        $request->user()->deleteWithData();

        return response()->noContent();
    }
}
