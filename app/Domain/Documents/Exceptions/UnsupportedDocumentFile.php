<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

/**
 * Berkas tidak memiliki parser (plan.md §5.3 UNSUPPORTED).
 *
 * Dibedakan dari DocumentParsingFailed karena akibatnya berbeda: berkas tidak didukung
 * tidak akan berhasil meski diproses ulang dengan berkas yang sama, sementara kegagalan
 * parsing dapat berhasil setelah retry.
 */
final class UnsupportedDocumentFile extends DocumentException {}
