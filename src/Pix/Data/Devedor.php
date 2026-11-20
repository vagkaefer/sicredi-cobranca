<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Data\Formata;

/**
 * Devedor da cobrança imediata: CPF ou CNPJ e nome.
 */
final class Devedor
{
    public readonly string $documento;
    public readonly string $nome;

    public function __construct(string $documento, string $nome)
    {
        $this->documento = Formata::digitos($documento);

        if (! in_array(strlen($this->documento), [11, 14], true)) {
            throw new InvalidArgumentException("Documento do devedor inválido: \"{$documento}\" (esperado CPF ou CNPJ).");
        }

        $nome = FormataPix::texto($nome, 200);
        if ($nome === null) {
            throw new InvalidArgumentException('Nome do devedor é obrigatório.');
        }
        $this->nome = $nome;
    }

    public function pessoaJuridica(): bool
    {
        return strlen($this->documento) === 14;
    }

    /**
     * @return array{cpf: string, nome: string}|array{cnpj: string, nome: string}
     */
    public function toArray(): array
    {
        return $this->pessoaJuridica()
            ? ['cnpj' => $this->documento, 'nome' => $this->nome]
            : ['cpf' => $this->documento, 'nome' => $this->nome];
    }
}
