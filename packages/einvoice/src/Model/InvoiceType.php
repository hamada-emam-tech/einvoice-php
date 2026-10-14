<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
}
