"""Pemilihan extractor berdasarkan jenis dokumen (plan.md §13.1, §37 Phase 4).

Phase 4 membangun empat extractor sesuai plan.md §28: faktur, struk, rekening koran, dan
settlement QRIS. Jenis dokumen di luar keempatnya belum punya extractor, dan itu dilaporkan
secara eksplisit sebagai `NoExtractorAvailable` alih-alih ditangani extractor terdekat.
Memaksakan schema faktur pada slip gaji akan menghasilkan field yang terisi tetapi salah
arti, yang jauh lebih berbahaya daripada dokumen yang jujur menunggu manusia.
"""

from __future__ import annotations

from app.extractors.bank_statement import BankStatementExtractor
from app.extractors.base import DocumentExtractor
from app.extractors.invoice import InvoiceExtractor
from app.extractors.qris import QrisSettlementExtractor
from app.extractors.receipt import ReceiptExtractor


class NoExtractorAvailable(Exception):
    """Jenis dokumen dikenali, tetapi belum ada extractor untuknya."""


_EXTRACTORS: tuple[DocumentExtractor, ...] = (
    InvoiceExtractor(),
    ReceiptExtractor(),
    BankStatementExtractor(),
    QrisSettlementExtractor(),
)


def supported_document_types() -> list[str]:
    return sorted(
        {document_type for extractor in _EXTRACTORS for document_type in extractor.document_types}
    )


def resolve_extractor(document_type: str) -> DocumentExtractor:
    for extractor in _EXTRACTORS:
        if document_type in extractor.document_types:
            return extractor

    raise NoExtractorAvailable(
        f"Belum ada extractor untuk jenis dokumen [{document_type}]. "
        f"Jenis yang didukung: {', '.join(supported_document_types())}."
    )
