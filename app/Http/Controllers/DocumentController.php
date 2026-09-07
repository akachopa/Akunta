<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\InboxFilter;
use App\Domain\Documents\Models\Document;
use App\Http\Presenters\DocumentPresenter;
use App\Http\Requests\StoreDocumentRequest;
use App\Services\DocumentProcessing\DocumentIngestionService;
use App\Services\DocumentProcessing\DocumentProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Document Inbox (plan.md §6, §35.2 "Inbox First", §37 Phase 3).
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentIngestionService $ingestion,
        private readonly DocumentProcessingService $processing,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(Request $request, Business $business): Response
    {
        $this->authorize('viewAny', [Document::class, $business]);

        $filter = InboxFilter::tryFrom((string) $request->query('filter')) ?? InboxFilter::All;

        $documents = $filter
            ->apply(Document::query()->forBusiness($business))
            ->latest('uploaded_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('documents/Index', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'filter' => $filter->value,
            'filters' => array_map(
                static fn (InboxFilter $case): array => ['value' => $case->value, 'label' => $case->label()],
                InboxFilter::cases()
            ),
            'counts' => $this->counts($business),
            'documentTypes' => array_map(
                static fn (DocumentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                DocumentType::selectableOnUpload()
            ),
            'uploadLimits' => [
                'max_files' => 20,
                'max_size_kb' => (int) config('akunta.documents.max_upload_size_kb', 25600),
                'extensions' => ['pdf', 'csv', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'],
            ],
            'documents' => [
                'data' => collect($documents->items())
                    ->map(fn (Document $document): array => $this->presenter->summary($document))
                    ->all(),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ],
            ],
        ]);
    }

    public function store(StoreDocumentRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('create', [Document::class, $business]);

        $result = $this->ingestion->ingestMany(
            $business,
            $request->uploadedFiles(),
            $request->user(),
            $request->documentTypeHint(),
        );

        $accepted = count($result['documents']);

        if ($result['failures'] !== []) {
            return back()->withErrors([
                'files' => sprintf(
                    '%d berkas gagal disimpan: %s',
                    count($result['failures']),
                    implode(', ', array_column($result['failures'], 'filename'))
                ),
            ]);
        }

        return back()->with('success', sprintf('%d berkas diterima dan masuk antrean proses.', $accepted));
    }

    public function show(Request $request, Business $business, Document $document): Response
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

        $user = $request->user();

        return Inertia::render('documents/Show', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'document' => $this->presenter->detail($document),
            'can' => [
                'reprocess' => $user?->can('reprocess', $document) ?? false,
                'archive' => $user?->can('archive', $document) ?? false,
                'download' => $user?->can('download', $document) ?? false,
                'review' => $user?->can('review', $document) ?? false,
            ],
        ]);
    }

    public function reprocess(Request $request, Business $business, Document $document): RedirectResponse
    {
        $this->authorize('reprocess', $document);

        $this->processing->reprocess($document, $request->user(), $request->string('reason')->value() ?: null);

        return back()->with('success', 'Dokumen dimasukkan kembali ke antrean proses.');
    }

    public function archive(Request $request, Business $business, Document $document): RedirectResponse
    {
        $this->authorize('archive', $document);

        $this->processing->archive($document, $request->user(), $request->string('reason')->value() ?: null);

        return back()->with('success', 'Dokumen diarsipkan.');
    }

    /**
     * Jumlah dokumen per tab, dipakai badge menu inbox (plan.md §6).
     *
     * @return array<string, int>
     */
    private function counts(Business $business): array
    {
        $counts = [];

        foreach (InboxFilter::cases() as $case) {
            $counts[$case->value] = $case->apply(Document::query()->forBusiness($business))->count();
        }

        return $counts;
    }
}
