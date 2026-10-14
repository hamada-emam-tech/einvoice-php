<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Exceptions;

/** The invoice data is wrong before anything was signed or sent: bad amounts, mixed currencies, missing fields. */
final class InvalidInvoice extends EinvoiceException
{
}
