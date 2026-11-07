<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeInterface;
use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Enums\EspecieDocumento;
use VagKaefer\SicrediCobranca\Enums\TipoCobranca;

/**
 * Dados para registrar um boleto (POST /boletos).
 */
final class BoletoRequest
{
    /**
     * @param string $seuNumero Número do título no seu sistema (máx. 10).
     * @param string|null $idTituloEmpresa Identificador interno (máx. 25), útil para conciliar e evitar duplicidade.
     * @param string|null $nossoNumero Deixe nulo para o Sicredi gerar.
     * @param list<string> $mensagens Até 4 linhas de 80 caracteres.
     * @param list<string> $informativos Até 5 linhas de 80 caracteres.
     * @param int|null $validadeAposVencimento Dias que o QR Code Pix continua válido após o vencimento (híbrido).
     * @param array<string, mixed>|null $beneficiarioFinal Só quando o crédito é de outra pessoa.
     * @param array<string, mixed> $extras Campos adicionais enviados sem tratamento (ex.: splitBoleto).
     */
    public function __construct(
        public readonly DateTimeInterface|string $dataVencimento,
        public readonly float $valor,
        public readonly string $seuNumero,
        public readonly Pagador $pagador,
        public readonly TipoCobranca $tipoCobranca = TipoCobranca::Hibrido,
        public readonly EspecieDocumento $especieDocumento = EspecieDocumento::DuplicataServicoIndicacao,
        public readonly ?string $idTituloEmpresa = null,
        public readonly ?string $nossoNumero = null,
        public readonly array $mensagens = [],
        public readonly array $informativos = [],
        public readonly ?Juros $juros = null,
        public readonly ?Multa $multa = null,
        public readonly ?Desconto $desconto = null,
        public readonly ?int $validadeAposVencimento = null,
        public readonly ?int $diasProtestoAuto = null,
        public readonly ?int $diasNegativacaoAuto = null,
        public readonly ?array $beneficiarioFinal = null,
        public readonly array $extras = [],
    ) {
        if (round($valor, 2) <= 0) {
            throw new InvalidArgumentException('O valor do boleto deve ser maior que zero.');
        }
        if (trim($seuNumero) === '') {
            throw new InvalidArgumentException('seuNumero é obrigatório.');
        }
        if ($diasProtestoAuto !== null && $diasNegativacaoAuto !== null) {
            throw new InvalidArgumentException('Protesto e negativação automáticos não podem ser usados juntos.');
        }
        foreach (['diasProtestoAuto' => $diasProtestoAuto, 'diasNegativacaoAuto' => $diasNegativacaoAuto] as $campo => $dias) {
            if ($dias !== null && ($dias < 3 || $dias > 99)) {
                throw new InvalidArgumentException("{$campo} deve ficar entre 3 e 99.");
            }
        }
    }

    /**
     * Corpo JSON do POST.
     *
     * Em boleto HIBRIDO, as datas de início de juros e multa NÃO são enviadas: com elas o Sicredi
     * registra um boleto tradicional, sem QR Code e sem avisar. Juros e multa passam a contar
     * a partir do dia seguinte ao vencimento.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $codigoBeneficiario): array
    {
        $comDatas = $this->tipoCobranca !== TipoCobranca::Hibrido;

        $dados = [
            'codigoBeneficiario' => $codigoBeneficiario,
            'tipoCobranca' => $this->tipoCobranca->value,
            'especieDocumento' => $this->especieDocumento->value,
            'dataVencimento' => Formata::data($this->dataVencimento),
            'valor' => Formata::valor($this->valor),
            'seuNumero' => Formata::texto($this->seuNumero, 10),
            'pagador' => $this->pagador->toArray(),
        ];

        $opcionais = [
            'idTituloEmpresa' => Formata::texto($this->idTituloEmpresa, 25),
            'nossoNumero' => $this->nossoNumero !== null ? Formata::digitos($this->nossoNumero) : null,
            'mensagens' => self::linhas($this->mensagens, 4),
            'informativos' => self::linhas($this->informativos, 5),
            'validadeAposVencimento' => $this->validadeAposVencimento,
            'diasProtestoAuto' => $this->diasProtestoAuto,
            'diasNegativacaoAuto' => $this->diasNegativacaoAuto,
            'beneficiarioFinal' => $this->beneficiarioFinal,
        ];

        $dados += array_filter($opcionais, fn($v) => $v !== null && $v !== [] && $v !== '');

        if ($this->juros !== null && $this->juros->valor > 0) {
            $dados += $this->juros->toArray($comDatas);
        }
        if ($this->multa !== null && $this->multa->valor > 0) {
            $dados += $this->multa->toArray($comDatas);
        }
        if ($this->desconto !== null) {
            $dados += $this->desconto->toArray();
        }

        return array_merge($dados, $this->extras);
    }

    /**
     * @param list<string> $linhas
     * @return list<string>
     */
    private static function linhas(array $linhas, int $max): array
    {
        $linhas = array_values(array_filter(array_map(fn($l) => Formata::texto((string) $l, 80), $linhas)));

        return array_slice($linhas, 0, $max);
    }
}
