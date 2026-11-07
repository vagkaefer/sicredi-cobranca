<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Enums\TipoPessoa;

final class Pagador
{
    public readonly string $documento;
    public readonly TipoPessoa $tipoPessoa;

    /**
     * Endereço, cidade, UF e CEP são obrigatórios quando o beneficiário tem validação de CEP
     * ativa, ou em boletos com protesto/negativação.
     */
    public function __construct(
        string $documento,
        public readonly string $nome,
        public readonly ?string $endereco = null,
        public readonly ?string $cidade = null,
        public readonly ?string $uf = null,
        public readonly ?string $cep = null,
        public readonly ?string $telefone = null,
        public readonly ?string $email = null,
        ?TipoPessoa $tipoPessoa = null,
    ) {
        $this->documento = Formata::digitos($documento);

        if (! in_array(strlen($this->documento), [11, 14], true)) {
            throw new InvalidArgumentException("Documento do pagador inválido: \"{$documento}\" (esperado CPF ou CNPJ).");
        }
        if (trim($nome) === '') {
            throw new InvalidArgumentException('Nome do pagador é obrigatório.');
        }

        $this->tipoPessoa = $tipoPessoa ?? TipoPessoa::doDocumento($this->documento);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $cep = Formata::digitos($this->cep);
        $telefone = Formata::digitos($this->telefone);
        $email = Formata::texto($this->email, 40);

        return array_filter([
            'tipoPessoa' => $this->tipoPessoa->value,
            'documento' => $this->documento,
            'nome' => Formata::texto($this->nome, 40),
            'endereco' => Formata::texto($this->endereco, 40),
            'cidade' => Formata::texto($this->cidade, 40),
            'uf' => $this->uf ? strtoupper(substr(trim($this->uf), 0, 2)) : null,
            'cep' => strlen($cep) === 8 ? $cep : null,
            // Telefone: no máximo 11 dígitos (DDD + número); descarta DDI/excesso pela esquerda.
            'telefone' => $telefone !== '' ? substr($telefone, -11) : null,
            // E-mail cortado ficaria inválido: só envia se couber no limite.
            'email' => $email !== null && mb_strlen(trim((string) $this->email)) <= 40 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        ], fn($v) => $v !== null && $v !== '');
    }
}
