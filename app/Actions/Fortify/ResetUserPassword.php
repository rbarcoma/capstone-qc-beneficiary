<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\UserManagement;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => [...$this->passwordRules(), ...($user->must_change_password ? [Password::min(12)->mixedCase()->numbers()->symbols()] : [])],
        ])->validate();

        app(UserManagement::class)->changePassword($user, $input['password'], reset: true);
    }
}
