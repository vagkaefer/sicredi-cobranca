<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use InvalidArgumentException;

/**
 * Dados para criar uma cobrança imediata (PUT /cob/{txid}).
 *
 * Textos são normalizados nos limites da API: solicitacaoPagador até 140 caracteres; em
 * infoAdicionais, nome até 50 e valor até 200.
 */
final class CobRequest
{
    public readonly string $valor;

    /** @var list<array{nome: string, valor: string}> */
    public readonly array $infoAdicionais;

    /**
     * @param float|int|string $valor Valor original (duas casas).
     * @param int $expiracao Validade em segundos, contada da criação (padrão: 1 hora).
     * @param array<int|string, string|array<string, string>> $infoAdicionais
     *        ['Pedido' => '123'] ou [['nome' => 'Pedido', 'valor' => '123']].
     * @param string|null $chave Chave Pix do recebedor; null usa a chave_pix da configuração.
     */
    public function __construct(
        float|int|string $valor,
        public readonly int $expiracao = 3600,
        public readonly ?Devedor $devedor = null,
        public readonly ?string $solicitacaoPagador = null,
        array $infoAdicionais = [],
        public readonly ?string $chave = null,
    ) {
        $this->valor = FormataPix::valor($valor);

        if ($expiracao <= 0) {
            throw new InvalidArgumentException("Expiração inválida: {$expiracao} (em segundos, maior que zero).");
        }
        if ($chave !== null && (trim($chave) === '' || mb_strlen($chave) > 77)) {
            throw new InvalidArgumentException('Chave Pix inválida: vazia ou com mais de 77 caracteres.');
        }

        $lista = [];
        foreach ($infoAdicionais as $nome => $info) {
            if (is_array($info)) {
                $nome = (string) ($info['nome'] ?? '');
                $info = (string) ($info['valor'] ?? '');
            }

            $nome = FormataPix::texto((string) $nome, 50);
            $info = FormataPix::texto((string) $info, 200);
            if ($nome === null || $info === null) {
                throw new InvalidArgumentException('infoAdicionais: nome e valor são obrigatórios em cada item.');
            }

            $lista[] = ['nome' => $nome, 'valor' => $info];
        }
        $this->infoAdicionais = $lista;
    }

    /**
     * Corpo da requisição.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $chavePadrao): array
    {
        return array_filter([
            'calendario' => ['expiracao' => $this->expiracao],
            'devedor' => $this->devedor?->toArray(),
            'valor' => ['original' => $this->valor],
            'chave' => $this->chave ?? $chavePadrao,
            'solicitacaoPagador' => FormataPix::texto($this->solicitacaoPagador, 140),
            'infoAdicionais' => $this->infoAdicionais,
        ], fn($v) => $v !== null && $v !== []);
    }
}
