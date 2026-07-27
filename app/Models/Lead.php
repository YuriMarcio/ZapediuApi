<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    public const CATEGORIES = ['Pizzaria', 'Açaí', 'Outros'];

    public const STAGES = [
        'novo_lead',
        'primeiro_contato',
        'interessado',
        'reuniao_marcada',
        'proposta_enviada',
        'documentacao_pendente',
        'cadastro_em_analise',
        'loja_ativada',
        'nao_convertido',
        'retomar_futuramente',
    ];

    protected $fillable = [
        'manager_id',
        'operation_id',
        'assigned_seller_id',
        'name',
        'category',
        'zone',
        'stage',
        'loss_reason',
        'last_contact_at',
    ];

    protected $casts = ['last_contact_at' => 'datetime'];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function operation(): BelongsTo { return $this->belongsTo(Operation::class); }

    public function assignedSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_seller_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }
}
