<?php

namespace App\Services;

use App\Enums\TwoFactorResult;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Support\IssuedTwoFactorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class TwoFactorService
{
    /**
     * Gera e envia um novo código, invalidando os anteriores do usuário.
     */
    public function issue(User $user, ?Request $request = null): IssuedTwoFactorCode
    {
        $this->invalidatePending($user);

        $plainCode = $this->generateCode();

        $record = $user->twoFactorCodes()->create([
            'code_hash' => Hash::make($plainCode),
            'expires_at' => Carbon::now()->addMinutes((int) config('two_factor.expires_in_minutes')),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        Mail::to($user->email)->send(new TwoFactorCodeMail($user, $plainCode, $record->expires_at));

        return new IssuedTwoFactorCode($record, $plainCode);
    }

    /**
     * Confere o código informado contra o desafio ativo do usuário.
     */
    public function verify(User $user, string $code): TwoFactorResult
    {
        $record = $user->twoFactorCodes()
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $record) {
            return TwoFactorResult::Missing;
        }

        if ($record->isExpired()) {
            return TwoFactorResult::Expired;
        }

        if ($record->attempts >= (int) config('two_factor.max_attempts')) {
            return TwoFactorResult::Throttled;
        }

        if (! Hash::check(trim($code), $record->code_hash)) {
            $record->increment('attempts');

            return $record->attempts >= (int) config('two_factor.max_attempts')
                ? TwoFactorResult::Throttled
                : TwoFactorResult::Invalid;
        }

        $record->forceFill(['consumed_at' => Carbon::now()])->save();

        return TwoFactorResult::Success;
    }

    /**
     * Segundos restantes até o usuário poder pedir um novo código.
     */
    public function secondsUntilResend(User $user): int
    {
        $last = $user->twoFactorCodes()->latest('id')->first();

        if (! $last) {
            return 0;
        }

        $available = $last->created_at->addSeconds((int) config('two_factor.resend_cooldown'));

        return max(0, (int) ceil(Carbon::now()->diffInSeconds($available, false)));
    }

    public function invalidatePending(User $user): void
    {
        $user->twoFactorCodes()
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);
    }

    protected function generateCode(): string
    {
        $length = max(4, (int) config('two_factor.code_length'));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
