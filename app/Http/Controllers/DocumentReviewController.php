<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Http\Requests\ConfirmDocumentFieldsRequest;
use App\Http\Requests\ConfirmDocumentTypeRequest;
use App\Services\DocumentProcessing\DocumentReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Review dokumen (plan.md §16, §37 Phase 4 "review UI untuk extracted fields").
 *
 * Tiga tindakan terpisah, bukan satu endpoint "simpan review", karena akibatnya berbeda:
 * menetapkan jenis dokumen membuka kembali ekstraksi, memastikan field mengubah nilai dan
 * confidence-nya, dan menyetujui dokumen adalah pernyataan manusia yang membawanya ke READY.
 */
class DocumentReviewController extends Controller
{
    public function __construct(private readonly DocumentReviewService $review) {}

    public function type(ConfirmDocumentTypeRequest $request, Business $business, Document $document): RedirectResponse
    {
        $this->authorize('review', $document);

        $this->review->confirmDocumentType(
            $document,
            $request->documentType(),
            $request->user(),
            $request->reason(),
        );

        return back()->with('success', 'Jenis dokumen disimpan dan dokumen dibaca ulang.');
    }

    public function fields(ConfirmDocumentFieldsRequest $request, Business $business, Document $document): RedirectResponse
    {
        $this->authorize('review', $document);

        $this->review->confirmFields(
            $document,
            $request->fieldValues(),
            $request->user(),
            $request->reason(),
        );

        return back()->with('success', 'Data dokumen diperbarui.');
    }

    public function approve(Request $request, Business $business, Document $document): RedirectResponse
    {
        $this->authorize('review', $document);

        $this->review->approve(
            $document,
            $request->user(),
            $request->string('reason')->value() ?: null,
        );

        return back()->with('success', 'Dokumen disetujui dan siap diproses.');
    }
}
