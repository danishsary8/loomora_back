<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\WithActiveAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteMeRequest;
use App\Http\Requests\Account\SurveyRequest;
use App\Http\Requests\Account\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\GoogleDeletionProof;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    use WithActiveAccount;

    public function update(UpdateMeRequest $request): UserResource
    {
        return $this->withActiveAccount($request->user(), function (User $user) use ($request) {
            $user->update($request->validated());

            return new UserResource($user);
        });
    }

    /** Skippable: an empty submit still marks the survey as done. */
    public function survey(SurveyRequest $request): UserResource
    {
        return $this->withActiveAccount($request->user(), function (User $user) use ($request) {
            $user->fill([
                'goal' => $request->validated('goal'),
                'heard_from' => $request->validated('heard_from'),
            ]);
            $user->forceFill(['survey_done' => true])->save();

            return new UserResource($user);
        });
    }

    /** Confirm, then delete everything immediately (docs/07 S14). */
    public function destroy(DeleteMeRequest $request, GoogleDeletionProof $proof): Response
    {
        $this->withActiveAccount($request->user(), function (User $user) use ($request, $proof) {
            $confirmation = $request->validated('google_confirmation');
            if ($confirmation !== null) {
                $proof->consume($user, $confirmation['challenge_id'], $confirmation['browser_verifier']);
            } else {
                $this->verifyDeletionPassword($user, $request->validated('password'));
            }

            $user->deleteWithData();
        });

        return response()->noContent();
    }

    private function verifyDeletionPassword(User $user, #[\SensitiveParameter] string $password): void
    {
        if ($user->password === null) {
            throw ValidationException::withMessages(['password' => [__('account.google_reauth_required')]]);
        }

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => [__('account.wrong_password')]]);
        }
    }
}
