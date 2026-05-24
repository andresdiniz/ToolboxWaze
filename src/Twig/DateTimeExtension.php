<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtro Twig |utc3
 *
 * Converte qualquer valor de data/hora para o fuso UTC-3 (Horário de Brasília)
 * APENAS na camada de apresentação. O banco de dados não é afetado.
 *
 * Uso nos templates:
 *
 *   {{ entity.criadoEm|utc3 }}                    → "24/05/2026 20:05"
 *   {{ entity.criadoEm|utc3('d/m/Y H:i') }}       → "24/05/2026 20:05"
 *   {{ entity.criadoEm|utc3('d/m/Y H:i:s') }}     → "24/05/2026 20:05:04"
 *   {{ '2026-05-24T23:05:04+00:00'|utc3 }}        → "24/05/2026 20:05"
 *   {{ null|utc3 }}                                → "" (vazio, sem erro)
 *
 * Aceita:
 *   - \DateTimeInterface (DateTime / DateTimeImmutable)
 *   - string em qualquer formato reconhecido por strtotime()
 *   - null / string vazia → retorna ''
 */
final class DateTimeExtension extends AbstractExtension
{
    private const DEFAULT_FORMAT = 'd/m/Y H:i';
    private const TIMEZONE       = 'America/Sao_Paulo'; // UTC-3 / UTC-2 DST

    public function getFilters(): array
    {
        return [
            new TwigFilter('utc3', $this->toUtc3(...)),
        ];
    }

    /**
     * @param \DateTimeInterface|string|null $value   Valor de entrada
     * @param string                         $format  Formato de saída (date())
     */
    public function toUtc3(mixed $value, string $format = self::DEFAULT_FORMAT): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $tz = new \DateTimeZone(self::TIMEZONE);

        if ($value instanceof \DateTimeInterface) {
            // Clona para não mutar o objeto original
            $dt = \DateTimeImmutable::createFromInterface($value)->setTimezone($tz);
            return $dt->format($format);
        }

        if (\is_string($value)) {
            // Tenta fazer parse da string; suporta ISO 8601, Y-m-d H:i:s, etc.
            $ts = strtotime($value);
            if ($ts === false) {
                return $value; // não reconheceu — devolve como está
            }

            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
            return $dt->format($format);
        }

        return (string) $value;
    }
}
