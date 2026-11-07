<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Enums;

enum EspecieDocumento: string
{
    case DuplicataMercantilIndicacao = 'DUPLICATA_MERCANTIL_INDICACAO';
    case DuplicataRural = 'DUPLICATA_RURAL';
    case NotaPromissoria = 'NOTA_PROMISSORIA';
    case NotaPromissoriaRural = 'NOTA_PROMISSORIA_RURAL';
    case NotaSeguros = 'NOTA_SEGUROS';
    case Recibo = 'RECIBO';
    case LetraCambio = 'LETRA_CAMBIO';
    case NotaDebito = 'NOTA_DEBITO';
    case DuplicataServicoIndicacao = 'DUPLICATA_SERVICO_INDICACAO';
    case Outros = 'OUTROS';
    case BoletoProposta = 'BOLETO_PROPOSTA';
    case CartaoCredito = 'CARTAO_CREDITO';
    case BoletoDeposito = 'BOLETO_DEPOSITO';
}
