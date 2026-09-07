<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\InboxFilter;
use App\Domain\Documents\Models\Document;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DocumentPresenter;
use App\Http\Requests\ConfirmDocumentFieldsRequest;
use App\Http\Requests\ConfirmDocumentTypeRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Services\DocumentProcessing\DocumentIngestionService;
use App\Services\DocumentProcessing\DocumentProcessingService;
use App\Services\DocumentProcessing\DocumentReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan.md §29.3:
 *
 * GET    /businesses/{id}/documents
 * POST   /businesses/{id}/documents
 * GET    /documents/{id}
 * POST   /documents/{id}/reprocess
 * POST   /documents/{id}/archive
 *
 * Ditambah endpoint review plan.md §29.8 yang menyangkut dokumen:
 *
 * POST   /documents/{id}/review/type
 * POST   /documents/{id}/review/fields
 * POST   /documents/{id}/review/approve
 *
 * GET /documents/{id} adalah endpoint yang dipakai klien untuk polling status pipeline
 * (plan.md §37 Phase 3 "processing status real-time/polling").
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentIngestionService $ingestion,
        private readonly DocumentProcessingService $processing,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewAny', [Document::class, $business]);

        $filter = InboxFilter::tryFrom((string) $request->query('filter')) ?? InboxFilter::All;

        // plan.md §40: list yang dipaginasi dan filter di sisi server.
        $documents = $filter
            ->apply(Document::query()->forBusiness($business))
            ->latest('uploaded_at')
            ->paginate(min((int) $request->integer('per_page', 25) ?: 25, 100));

        return response()->json([
            'data' => collect($documents->items())
                ->map(fn (Document $document): array => $this->presenter->summary($document))
                ->all(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function store(StoreDocumentRequest $request, Business $business): JsonResponse
    {
        $this->authorize('create', [Document::class, $business]);

        $result = $this->ingestion->ingestMany(
            $business,
            $request->uploadedFiles(),
            $request->user(),
            $request->documentTypeHint(),
        );

        /*
         * 207 dipakai ketika sebagian berkas gagal: klien harus tahu berkas mana yang
         * masuk dan mana yang perlu diunggah ulang, sehingga status tunggal 201 atau 422
         * akan menghilangkan informasi.
         */
        $status = $result['failures'] === [] ? 201 : 207;

        return response()->json([
            'data' => array_map(
                fn (Document $document): array => $this->presenter->summary($document),
                $result['documents']
            ),
            'failures' => $result['failures'],
        ], $status);
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load([
            'pages',
            'processingJobs',
            'uploader',
            'archiver',
            'reviewer',
            'fields.confirmer',
            'extractions',
            'predictions.candidates',
            'transactions',
        ]);

        return response()->json(['data' => $this->presenter->detail($document)]);
    }

    public function reprocess(Request $request, Document $document): JsonResponse
    {
        $this->authorize('reprocess', $document);

        $this->processing->reprocess($document, $request->user(), $request->string('reason')->value() ?: null);

        return response()->json(['data' => $this->presenter->summary($document->refresh())]);
    }

    public function archive(Request $request, Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $this->processing->archive($document, $request->user(), $request->string('reason')->value() ?: null);

        return response()->json(['data' => $this->presenter->summary($document->refresh())]);
    }

    /**
     * plan.md §16.2: reviewer menetapkan jenis dokumen.
     */
    public function confirmType(
        ConfirmDocumentTypeRequest $request,
        Document $document,
        DocumentReviewService $review,
    ): JsonResponse {
        $this->authorize('review', $document);

        $review->confirmDocumentType(
            $document,
            $request->documentType(),
            $request->user(),
            $request->reason(),
        );

        return response()->json(['data' => $this->presenter->summary($document->refresh())]);
    }

    /**
     * plan.md §16.2: reviewer memastikan nilai field hasil ekstraksi.
     */
    public function confirmFields(
        ConfirmDocumentFieldsRequest $request,
        Document $document,
        DocumentReviewService $review,
    ): JsonResponse {
        $this->authorize('review', $document);

        $review->confirmFields(
            $document,
            $request->fieldValues(),
            $request->user(),
            $request->reason(),
        );

        return response()->json(['data' => $this->presenter->summary($document->refresh())]);
    }

    /**
     * plan.md §16.1: persetujuan manusia atas dokumen.
     */
    public function approve(Request $request, Document $document, DocumentReviewService $review): JsonResponse
    {
        $this->authorize('review', $document);

        $review->approve($document, $request->user(), $request->string('reason')->value() ?: null);

        return response()->json(['data' => $this->presenter->summary($document->refresh())]);
    }
}
