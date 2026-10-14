<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Contracts;

use HamadaEmamTech\Einvoice\Document\PreparedDocument;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Result\SubmissionResult;

/**
 * One tax authority (ZATCA, Egypt ETA...).
 *
 * Every authority follows the same steps: prepare the invoice in the authority's format →
 * sign it with a KeySource → store the signed document → submit it → read the result per document.
 * The key is passed to sign() rather than held by the authority, so one instance can sign for
 * many taxpayers or devices, each with its own key.
 */
interface TaxAuthority
{
    /** The short name this authority is registered under, e.g. "zatca". */
    public function name(): string;

    /** Build the authority's document (UBL XML, canonical JSON...). Deterministic: same invoice, same bytes. */
    public function prepare(Invoice $invoice): PreparedDocument;

    /** @throws \HamadaEmamTech\Einvoice\Exceptions\SigningError */
    public function sign(PreparedDocument $document, KeySource $key): SignedDocument;

    /**
     * Send one or more signed documents. A document the authority refuses is returned in
     * SubmissionResult::$rejected, not thrown.
     *
     * @throws \HamadaEmamTech\Einvoice\Exceptions\AuthorityError when the call itself fails
     */
    public function submit(SignedDocument ...$documents): SubmissionResult;

    /** @param string $authorityId the ID from AcceptedDocument::$authorityId */
    public function status(string $authorityId): DocumentStatus;
}
