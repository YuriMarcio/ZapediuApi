<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ImageUploadService;
use App\Services\Media\StoreBannerComposer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera o banner do card de loja no carrossel do WhatsApp (§4 do doc): 1200x530 com o logo
 * centralizado (contain, sem upscale) sobre um fundo em gradiente — o carrossel manda o
 * logo transparente cru como imagem do card, e o cliente (WhatsApp/FlowBridge) faz
 * object-fit: cover nele, cortando as bordas. Compor um banner opaco de proporção fixa
 * resolve isso sem depender do client final.
 */
class GenerateStoreCarouselBanners extends Command
{
    protected $signature = 'stores:generate-carousel-banners {--store=} {--force}';

    protected $description = 'Compõe o banner 1200x530 do carrossel (logo centralizado sobre gradiente) pras lojas ativas';

    public function handle(ImageUploadService $imageUploadService, StoreBannerComposer $composer): int
    {
        $query = Store::query()->where('is_active', true)->whereNotNull('logo_url')->where('logo_url', '!=', '');

        if ($slug = $this->option('store')) {
            $query->where('slug', $slug);
        }

        if (! $this->option('force')) {
            $query->whereNull('carousel_banner_url');
        }

        $stores = $query->with('category:id,slug')->get();

        $this->info("Gerando banner pra {$stores->count()} loja(s)...");

        foreach ($stores as $store) {
            try {
                $logoBytes = Http::timeout(15)->get((string) $store->logo_url)->throw()->body();
                $banner = $composer->compose($logoBytes, $store->category?->slug);

                $url = $imageUploadService->uploadBinary($banner, $store->slug.'/carousel-banner');

                $store->update(['carousel_banner_url' => $url]);

                $this->info("✔ {$store->slug} -> {$url}");
            } catch (\Throwable $e) {
                Log::warning('Falha ao gerar banner de carrossel.', ['store_id' => $store->id, 'error' => $e->getMessage()]);
                $this->warn("✘ {$store->slug}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
