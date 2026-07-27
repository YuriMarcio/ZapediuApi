<?php

namespace App\Services\Media;

use Intervention\Image\Laravel\Facades\Image;

/**
 * Compõe um banner 1200x1200 (quadrado) com o logo da loja centralizado (trim + upscale,
 * sem caixa branca) sobre um fundo em gradiente escolhido pela categoria — usado tanto pro
 * card do carrossel do WhatsApp (`stores:generate-carousel-banners`) quanto pro "Banner do
 * cardápio" padrão quando o lojista sobe uma logo sem escolher um banner próprio.
 *
 * Quadrado, não 1200x530: o card do carrossel no WhatsApp mobile (o que importa de
 * verdade) renderiza a imagem num slot ~quadrado — um banner 2,27:1 sobrava espaço em
 * branco em cima/embaixo (letterbox) e a logo saía pequena.
 */
class StoreBannerComposer
{
    private const BANNER_WIDTH = 1200;

    private const BANNER_HEIGHT = 1200;

    private const LOGO_MAX_WIDTH = 650;

    private const LOGO_MAX_HEIGHT = 650;

    /**
     * @return string bytes webp do banner pronto
     */
    public function compose(string $logoBytes, ?string $categorySlug): string
    {
        $logo = Image::read($this->prepareLogo($logoBytes));

        [$from, $to] = $this->gradientForCategory($categorySlug);
        $canvas = Image::read($this->gradientPng($from, $to));
        $canvas->place($logo, 'center');

        return (string) $canvas->toWebp(90);
    }

    /**
     * Redimensiona o logo pra caber na caixa 760×380 (COM upscale permitido) depois de
     * remover o fundo claro sólido e fazer trim da transparência — sem isso, logos com
     * canvas transparente grande ao redor da arte acabam minúsculas no banner.
     */
    private function prepareLogo(string $bytes): string
    {
        $src = imagecreatefromstring($bytes);

        if ($src === false) {
            throw new \RuntimeException('Não foi possível decodificar o logo.');
        }

        // Remove fundo branco sólido do arquivo original
        $this->stripNearWhiteBackground($src);

        // Faz o trim da transparência — encontra o bounding box dos pixels visíveis
        [$x, $y, $w, $h] = $this->getBoundingBoxOfVisiblePixels($src);

        if ($w <= 0 || $h <= 0) {
            throw new \RuntimeException('Nenhum pixel visível encontrado após remover fundo.');
        }

        // Cria novo canvas apenas com a área visível
        $trimmed = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        imagedestroy($src);

        if ($trimmed === false) {
            throw new \RuntimeException('Falha ao fazer crop do logo.');
        }

        // Redimensiona a área visível pro target, permitindo UPSCALE
        $scale = min(self::LOGO_MAX_WIDTH / $w, self::LOGO_MAX_HEIGHT / $h);
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));

        $im = imagescale($trimmed, $newW, $newH);
        imagedestroy($trimmed);

        if ($im === false) {
            throw new \RuntimeException('Falha ao redimensionar o logo.');
        }

        // imagecrop()/imagescale() devolvem um GdImage novo que NÃO herda o flag
        // imagesavealpha do original — os pixels continuam com o canal alfa certo em
        // memória, mas sem reativar o flag aqui o imagepng() grava tudo opaco.
        imagealphablending($im, false);
        imagesavealpha($im, true);

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /**
     * Encontra o bounding box (menor retângulo) que contém todos os pixels
     * não-transparentes da imagem. Retorna [x, y, width, height].
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function getBoundingBoxOfVisiblePixels(\GdImage $im): array
    {
        $w = imagesx($im);
        $h = imagesy($im);

        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 0x7F;

                if ($a < 100) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return [0, 0, 0, 0];
        }

        return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
    }

    /**
     * Flood fill a partir dos 4 cantos removendo pixels próximos da cor do canto (tolerância
     * por distância Manhattan) — só o fundo conectado à borda vira transparente, arte
     * interna (ex.: destaques brancos no meio do logo) fica intacta. Só roda se os cantos
     * realmente parecerem fundo liso e claro; logos que já ocupam a borda inteira com arte
     * ficam como estão.
     */
    private function stripNearWhiteBackground(\GdImage $im, int $tolerance = 40): void
    {
        $w = imagesx($im);
        $h = imagesy($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $seed = imagecolorat($im, 0, 0);
        $sr = ($seed >> 16) & 0xFF;
        $sg = ($seed >> 8) & 0xFF;
        $sb = $seed & 0xFF;

        if ($sr < 200 || $sg < 200 || $sb < 200) {
            return;
        }

        $visited = array_fill(0, $w * $h, false);
        $stack = [0, $w - 1, ($h - 1) * $w, ($h - 1) * $w + ($w - 1)];

        while ($stack !== []) {
            $idx = array_pop($stack);
            if ($visited[$idx]) {
                continue;
            }
            $visited[$idx] = true;

            $x = $idx % $w;
            $y = intdiv($idx, $w);
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 0x7F;

            if ($a >= 100) {
                continue;
            }

            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;

            if (abs($r - $sr) + abs($g - $sg) + abs($b - $sb) > $tolerance) {
                continue;
            }

            imagesetpixel($im, $x, $y, (127 << 24) | ($r << 16) | ($g << 8) | $b);

            if ($x + 1 < $w && ! $visited[$idx + 1]) {
                $stack[] = $idx + 1;
            }
            if ($x - 1 >= 0 && ! $visited[$idx - 1]) {
                $stack[] = $idx - 1;
            }
            if ($y + 1 < $h && ! $visited[$idx + $w]) {
                $stack[] = $idx + $w;
            }
            if ($y - 1 >= 0 && ! $visited[$idx - $w]) {
                $stack[] = $idx - $w;
            }
        }
    }

    /**
     * PNG do fundo em gradiente vertical — Intervention não tem gradient nativo, então
     * desenha pixel a pixel via GD puro e devolve os bytes prontos pro Image::read().
     */
    private function gradientPng(string $hexFrom, string $hexTo): string
    {
        [$r1, $g1, $b1] = $this->hexToRgb($hexFrom);
        [$r2, $g2, $b2] = $this->hexToRgb($hexTo);

        $width = self::BANNER_WIDTH;
        $height = self::BANNER_HEIGHT;

        $im = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            $r = (int) round($r1 + ($r2 - $r1) * $ratio);
            $g = (int) round($g1 + ($g2 - $g1) * $ratio);
            $b = (int) round($b1 + ($b2 - $b1) * $ratio);
            $color = imagecolorallocate($im, $r, $g, $b);
            imageline($im, 0, $y, $width - 1, $y, $color);
        }

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @return array{0: string, 1: string} [cor do topo, cor de baixo]
     */
    private function gradientForCategory(?string $slug): array
    {
        return match ($slug) {
            'cat_acai'       => ['#f4eafa', '#d8bce8'],
            'cat_lanches'    => ['#f7b733', '#c0392b'],
            'cat_cafeteria'  => ['#f4e9da', '#dcb98a'],
            'cat_saudavel'   => ['#eafaf1', '#a3d9a5'],
            'cat_pizza'      => ['#ffecd2', '#e8735a'],
            'cat_pastel'     => ['#fff3c4', '#f0b429'],
            'cat_japonesa'   => ['#fbe4e4', '#d6455d'],
            'cat_gelateria'  => ['#e0f7ff', '#a1c4fd'],
            'cat_mexicana'   => ['#ffe9b3', '#f2622e'],
            'cat_padaria'    => ['#f5e6d3', '#c99b5f'],
            'cat_mercadinho' => ['#e0f7fa', '#66a6c9'],
            'cat_farmacia'   => ['#eef7ff', '#a9c9e8'],
            'cat_refeicao'   => ['#ffe0cc', '#ff8a65'],
            default          => ['#eef0f3', '#c9ced6'],
        };
    }
}
