<?php

namespace App\DataTransferObjects\Whatsapp;

readonly class CarouselProductCardData
{
    public function __construct(
        public string $title,
        public string $description,
        public string $imageUrl,
        public string $buttonPayload,
        public string $buttonLabel = 'Comprar',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->title."\n".$this->description,
            'image' => $this->imageUrl,
            'buttons' => [
                [
                    'id' => $this->buttonPayload,
                    'label' => $this->buttonLabel,
                    'type' => 'REPLY',
                ],
            ],
        ];
    }
}
