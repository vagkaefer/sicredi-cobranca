<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Normalizações exigidas pelos campos da API.
 *
 * @internal
 */
final class Formata
{
    public static function digitos(?string $valor): string
    {
        return (string) preg_replace('/\D/', '', (string) $valor);
    }

    /**
     * Remove quebras de linha e espaços repetidos e corta no limite do campo.
     */
    public static function texto(?string $valor, int $max): ?string
    {
        $valor = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }

    public static function data(DateTimeInterface|string $data): string
    {
        if ($data instanceof DateTimeInterface) {
            return $data->format('Y-m-d');
        }

        $data = trim($data);
        foreach (['Y-m-d', 'd/m/Y'] as $formato) {
            $d = DateTimeImmutable::createFromFormat('!' . $formato, $data);
            if ($d !== false && $d->format($formato) === $data) {
                return $d->format('Y-m-d');
            }
        }

        throw new InvalidArgumentException("Data inválida: \"{$data}\". Use Y-m-d ou d/m/Y.");
    }

    public static function valor(float|int|string $valor): float
    {
        return round((float) $valor, 2);
    }
}
