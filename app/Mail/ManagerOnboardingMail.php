<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ManagerOnboardingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $owner,
        public readonly Company $company,
        public readonly string $defaultPassword,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Acesso à sua loja no Zapediu?')
            ->view('emails.manager-onboarding')
            ->with([
                'storeName'    => $this->company->trade_name,
                'email'        => $this->owner->email,
                'password'     => $this->defaultPassword,
                'panelUrl'     => 'https://zapediu.com/login',
                'supportPhone' => '(98) 98765-4321',
                'supportEmail' => 'suporte@zapediu.com.br',
                'logoUrl'      => 'https://pub-b685ab7948c34d1097563860d887d004.r2.dev/LogoOficial/logosvg.svg',
            ]);
    }
}
