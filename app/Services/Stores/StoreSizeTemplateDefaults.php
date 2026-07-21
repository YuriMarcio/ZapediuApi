<?php

namespace App\Services\Stores;

class StoreSizeTemplateDefaults
{
    /**
     * @return array<int, array{key: string, label: string, sublabel: string}>
     */
    public static function for(?string $businessType): array
    {
        return match ($businessType) {
            'pizzaria' => [
                ['key' => 'pequena', 'label' => 'Pequena', 'sublabel' => '25cm'],
                ['key' => 'media',   'label' => 'Média',   'sublabel' => '30cm'],
                ['key' => 'grande',  'label' => 'Grande',  'sublabel' => '35cm'],
                ['key' => 'familia', 'label' => 'Família',  'sublabel' => '40cm'],
            ],
            'acaiteria' => [
                ['key' => '300ml', 'label' => '300ml',    'sublabel' => 'Pequeno'],
                ['key' => '500ml', 'label' => '500ml',    'sublabel' => 'Médio'],
                ['key' => '700ml', 'label' => '700ml',    'sublabel' => 'Grande'],
                ['key' => '1l',    'label' => '1 Litro',  'sublabel' => 'Família'],
            ],
            default => [],
        };
    }
}
