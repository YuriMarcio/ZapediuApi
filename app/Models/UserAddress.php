<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\GeocodeService;

class UserAddress extends Model
{
    protected $fillable = [
        'user_id',
        'street',
        'number',
        'district',
        'complement',
        'city',
        'state',
        'zip_code',
        'formatted',
        'notes',
        'is_primary',
        'latitude',
        'longitude'
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (UserAddress $address) {
            // Verifica se houve mudança em qualquer campo de endereço
            $fields = ['street', 'number', 'city', 'state', 'zip_code', 'formatted'];

            if ($address->isDirty($fields)) {
                // Se o 'formatted' tiver conteúdo e os campos individuais estiverem vazios,
                // usamos o 'formatted' para buscar as coordenadas.
                $fullText = $address->formatted;

                // Caso o formatted esteja vazio (ex: cadastro via Dashboard), montamos a string
                if (empty($fullText)) {
                    $fullText = "{$address->street}, {$address->number}, {$address->city}, {$address->state}";
                }

                // Log para você debugar no terminal se a string está indo certa
                \Log::info("Buscando coordenadas para: " . $fullText);

                $coords = GeocodeService::getCoordinates($fullText);
                \Log::info("Resultado coordenadas:", $coords ?? ['null']);

                if ($coords) {
                    $address->latitude = (float) $coords['latitude'];
                    $address->longitude = (float) $coords['longitude'];
                }
            }
        });
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
