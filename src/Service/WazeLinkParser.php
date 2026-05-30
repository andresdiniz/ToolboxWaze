<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Utilitários para extrair informações de URLs do editor Waze (WME).
 *
 * Exemplos de URLs suportadas:
 *   https://www.waze.com/pt-BR/editor?env=row&permanentHazards=12345
 *   https://www.waze.com/pt-BR/editor?env=row&venues=ABCDEF
 *   https://www.waze.com/pt-BR/editor?env=row&lat=-20.68&lon=-43.79&zoom=17
 */
final class WazeLinkParser
{
    /**
     * Extrai o ID numérico de um Permanent Hazard de um link WME.
     * Retorna null se o parâmetro não estiver presente ou não for numérico.
     */
    public static function extractHazardId(string $url): ?int
    {
        if (preg_match('/[?&]permanentHazards=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extrai o ID de um Place (venue) de um link WME.
     * Retorna null se o parâmetro não estiver presente.
     */
    public static function extractVenueId(string $url): ?string
    {
        if (preg_match('/[?&]venues=([^&]+)/', $url, $m)) {
            return urldecode($m[1]);
        }

        return null;
    }

    /**
     * Extrai as coordenadas lat/lon de um link WME.
     * Retorna ['lat' => float, 'lon' => float] ou null se não encontradas.
     *
     * @return array{lat: float, lon: float}|null
     */
    public static function extractCoords(string $url): ?array
    {
        $lat = null;
        $lon = null;

        try {
            $parsed = parse_url($url);
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $qs);
                if (isset($qs['lat'], $qs['lon'])) {
                    $lat = (float) $qs['lat'];
                    $lon = (float) $qs['lon'];
                }
            }
        } catch (\Throwable) {
            // mantém null
        }

        // fallback via regex para URLs malformadas
        if ($lat === null) {
            preg_match('/[?&]lat=(-?[\d.]+)/', $url, $mLat);
            preg_match('/[?&]lon=(-?[\d.]+)/', $url, $mLon);
            if ($mLat && $mLon) {
                $lat = (float) $mLat[1];
                $lon = (float) $mLon[1];
            }
        }

        if ($lat === null || $lon === null) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon];
    }

    /**
     * Valida se uma URL contém o parâmetro permanentHazards com valor numérico.
     */
    public static function isValidHazardLink(string $url): bool
    {
        return self::extractHazardId($url) !== null;
    }

    /**
     * Valida se uma URL contém o parâmetro venues.
     */
    public static function isValidPlaceLink(string $url): bool
    {
        return self::extractVenueId($url) !== null;
    }
}
