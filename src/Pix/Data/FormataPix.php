<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Normalizações exigidas pelos campos da API Pix.
 *
 * @internal
 */
final class FormataPix
{
    /** Fuso usado para datas sem fuso explícito. */
    public const FUSO_PADRAO = 'America/Sao_Paulo';

    /**
     * Valor monetário no formato da API: string com duas casas e ponto ("\d{1,10}\.\d{2}").
     */
    public static function valor(float|int|string $valor): string
    {
        if (is_string($valor)) {
            $valor = trim($valor);
            // "1.234,56" ou "10,50" (formato brasileiro).
            if (str_contains($valor, ',')) {
                $valor = str_replace(['.', ','], ['', '.'], $valor);
            }
            if (! is_numeric($valor)) {
                throw new InvalidArgumentException("Valor inválido: \"{$valor}\".");
            }
        }

        $formatado = number_format(round((float) $valor, 2), 2, '.', '');

        if ((float) $formatado <= 0 || ! preg_match('/^\d{1,10}\.\d{2}$/', $formatado)) {
            throw new InvalidArgumentException("Valor inválido: \"{$formatado}\" (deve ser maior que zero, com até 10 dígitos inteiros).");
        }

        return $formatado;
    }

    /**
     * Remove quebras de linha e espaços repetidos e corta no limite do campo.
     */
    public static function texto(?string $valor, int $max): ?string
    {
        $valor = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }

    /**
     * Data e hora em UTC no formato RFC 3339, exigido nos filtros de consulta.
     * Strings sem fuso são interpretadas em America/Sao_Paulo; "Y-m-d" e "d/m/Y" valem 00:00.
     */
    public static function dataHoraUtc(DateTimeInterface|string $data): string
    {
        return self::dataHora($data)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function dataHora(DateTimeInterface|string $data): DateTimeImmutable
    {
        if ($data instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($data);
        }

        $fuso = new DateTimeZone(self::FUSO_PADRAO);
        $data = trim($data);

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $formato) {
            $d = DateTimeImmutable::createFromFormat('!' . $formato, $data, $fuso);
            if ($d !== false && $d->format($formato) === $data) {
                return $d;
            }
        }

        try {
            return new DateTimeImmutable($data, $fuso);
        } catch (Throwable) {
            throw new InvalidArgumentException("Data inválida: \"{$data}\".");
        }
    }

    /**
     * Lê uma data da resposta (RFC 3339); null quando vazia ou inválida.
     */
    public static function lerData(mixed $valor): ?DateTimeImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($valor));
        } catch (Throwable) {
            return null;
        }
    }

    public static function lerTexto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Lê o valor monetário da resposta, que pode vir como "10.00" ou {"original": "10.00"}.
     */
    public static function lerValor(mixed $valor): float
    {
        if (is_array($valor)) {
            $valor = $valor['original'] ?? $valor['valor'] ?? null;
        }

        return is_numeric($valor) ? round((float) $valor, 2) : 0.0;
    }
}
