import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

interface UploadLimits {
    max_files: number;
    max_size_kb: number;
    extensions: string[];
}

interface Props {
    businessId: string;
    documentTypes: { value: string; label: string }[];
    limits: UploadLimits;
    error?: string;
}

/**
 * Upload dokumen dengan drag-and-drop (plan.md §5.2).
 *
 * plan.md §5.2 menegaskan user tidak wajib menentukan jenis dokumen, sehingga pemilih
 * jenis di sini bersifat opsional dan default-nya dibiarkan kosong.
 *
 * Validasi ekstensi dan ukuran di komponen ini hanya untuk memberi umpan balik cepat.
 * Penegakan sebenarnya ada di StoreDocumentRequest, karena validasi klien dapat dilewati.
 */
export default function DocumentUploader({ businessId, documentTypes, limits, error }: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [selected, setSelected] = useState<File[]>([]);
    const [documentType, setDocumentType] = useState('');
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState(0);
    const [localErrors, setLocalErrors] = useState<string[]>([]);

    const accept = limits.extensions.map((extension) => `.${extension}`).join(',');
    const maxSizeBytes = limits.max_size_kb * 1024;

    const validate = (files: File[]): { valid: File[]; errors: string[] } => {
        const errors: string[] = [];
        const valid: File[] = [];

        files.forEach((file) => {
            const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

            if (!limits.extensions.includes(extension)) {
                errors.push(`${file.name}: tipe berkas tidak didukung.`);
                return;
            }

            if (file.size > maxSizeBytes) {
                errors.push(`${file.name}: ukuran melebihi ${limits.max_size_kb} KB.`);
                return;
            }

            valid.push(file);
        });

        if (valid.length > limits.max_files) {
            errors.push(`Maksimal ${limits.max_files} berkas per unggahan.`);

            return { valid: valid.slice(0, limits.max_files), errors };
        }

        return { valid, errors };
    };

    const addFiles = (incoming: FileList | null) => {
        if (!incoming || incoming.length === 0) {
            return;
        }

        const { valid, errors } = validate(Array.from(incoming));

        setLocalErrors(errors);
        setSelected((current) => [...current, ...valid].slice(0, limits.max_files));
    };

    const submit = () => {
        if (selected.length === 0) {
            return;
        }

        setUploading(true);
        setProgress(0);

        router.post(
            `/businesses/${businessId}/documents`,
            {
                files: selected,
                document_type: documentType || null,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onProgress: (event) => setProgress(event?.percentage ?? 0),
                onSuccess: () => {
                    setSelected([]);
                    setDocumentType('');
                    setLocalErrors([]);

                    if (inputRef.current) {
                        inputRef.current.value = '';
                    }
                },
                onFinish: () => {
                    setUploading(false);
                    setProgress(0);
                },
            },
        );
    };

    return (
        <div>
            <div
                role="button"
                tabIndex={0}
                onClick={() => inputRef.current?.click()}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        inputRef.current?.click();
                    }
                }}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    addFiles(event.dataTransfer.files);
                }}
                className={`flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-10 text-center transition ${
                    dragging
                        ? 'border-teal-500 bg-teal-50'
                        : 'border-slate-300 bg-slate-50 hover:border-teal-400'
                }`}
            >
                <p className="text-sm font-medium text-slate-700">
                    Tarik berkas ke sini atau klik untuk memilih
                </p>
                <p className="mt-1 text-xs text-slate-500">
                    {limits.extensions.join(', ').toUpperCase()} · maksimal{' '}
                    {Math.round(limits.max_size_kb / 1024)} MB per berkas · hingga{' '}
                    {limits.max_files} berkas
                </p>
                <p className="mt-3 text-xs text-slate-500">
                    Jenis dokumen tidak perlu ditentukan. Sistem membaca berkasnya sendiri.
                </p>

                <input
                    ref={inputRef}
                    type="file"
                    name="files"
                    multiple
                    accept={accept}
                    className="hidden"
                    onChange={(event) => addFiles(event.target.files)}
                />
            </div>

            {selected.length > 0 && (
                <ul className="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200 text-sm">
                    {selected.map((file, index) => (
                        <li
                            key={`${file.name}-${index}`}
                            className="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            <span className="min-w-0 truncate text-slate-700">{file.name}</span>
                            <button
                                type="button"
                                className="shrink-0 text-xs text-slate-500 hover:text-rose-600"
                                onClick={() =>
                                    setSelected((current) =>
                                        current.filter((_, position) => position !== index),
                                    )
                                }
                            >
                                Hapus
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {(localErrors.length > 0 || error) && (
                <ul className="mt-3 space-y-1 text-xs text-rose-600">
                    {error && <li>{error}</li>}
                    {localErrors.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-3">
                <select
                    className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                    value={documentType}
                    onChange={(event) => setDocumentType(event.target.value)}
                >
                    <option value="">Jenis dokumen (opsional)</option>
                    {documentTypes.map((type) => (
                        <option key={type.value} value={type.value}>
                            {type.label}
                        </option>
                    ))}
                </select>

                <button
                    type="button"
                    onClick={submit}
                    disabled={selected.length === 0 || uploading}
                    className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50"
                >
                    {uploading
                        ? `Mengunggah… ${progress}%`
                        : `Unggah ${selected.length || ''}`.trim()}
                </button>

                <span className="text-xs text-slate-500">
                    Berkas diproses di belakang layar; halaman tidak perlu ditunggu.
                </span>
            </div>
        </div>
    );
}
