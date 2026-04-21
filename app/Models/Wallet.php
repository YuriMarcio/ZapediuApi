<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'plan_id',
        'balance_pix',
        'balance_card',
        'balance_total',
        'is_enabled_withdrawal',
        'is_active',
        'mp_access_token',
        'mp_refresh_token',
        'mp_public_key',
        'mp_user_id',
        'mp_token_type',
        'mp_expires_at',
    ];

    /**
     * Casts para garantir segurança e tipos de dados corretos.
     */
    protected $casts = [
        'mp_access_token' => 'encrypted',
        'mp_refresh_token' => 'encrypted',
        'mp_expires_at' => 'datetime',
        'is_enabled_withdrawal' => 'boolean',
        'is_active' => 'boolean',
        'balance_pix' => 'decimal:2',
        'balance_card' => 'decimal:2',
        'balance_total' => 'decimal:2',
    ];

    /**
     * Relacionamento com a Empresa (Dona da Carteira)
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relacionamento com o Plano (Opcional)
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * SCOPES E HELPERS ESTRATÉGICOS
     */

    // Verifica se a integração com Mercado Pago está ativa
    public function hasMpIntegration(): bool
    {
        return !empty($this->mp_access_token) && ($this->mp_expires_at > now() || is_null($this->mp_expires_at));
    }

    // Verifica se o lojista pode sacar o dinheiro
    public function canWithdraw(): bool
    {
        return $this->is_enabled_withdrawal && $this->balance_total > 0;
    }

    // Atualiza o saldo total automaticamente ao salvar
    protected static function booted()
    {
        static::saving(function ($wallet) {
            $wallet->balance_total = $wallet->balance_pix + $wallet->balance_card;
        });
    }
}