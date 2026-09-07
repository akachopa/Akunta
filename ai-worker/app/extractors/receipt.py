"""Extractor struk dan nota (plan.md §14.2, §28).

Berbeda dari faktur, struk tidak selalu punya nomor dokumen dan hampir tidak pernah punya
tanggal jatuh tempo, tetapi selalu punya penjual dan total. Field wajibnya disesuaikan
dengan kenyataan itu supaya struk yang sah tidak ditolak hanya karena tidak bernomor.
"""

from __future__ import annotations

from typing import Any

from app.extractors.base import (
    KIND_DATE,
    KIND_MONEY,
    KIND_TEXT,
    DocumentExtractor,
    ExtractedField,
    FieldSpec,
)
from app.extractors.invoice import validate_invoice_totals


class ReceiptExtractor(DocumentExtractor):
    name = "receipt"
    version = "1.0"
    document_types = (
        "receipt",
        "cash_receipt",
        "cash_disbursement",
        "rent_receipt",
        "repair_receipt",
        "shipping_receipt",
        "receivable_payment_receipt",
    )

    fields = (
        FieldSpec("document_number", KIND_TEXT),
        FieldSpec("document_date", KIND_DATE, required=True),
        FieldSpec("merchant_name", KIND_TEXT, required=True),
        FieldSpec("currency", KIND_TEXT),
        FieldSpec("subtotal", KIND_MONEY),
        FieldSpec("tax", KIND_MONEY),
        FieldSpec("total", KIND_MONEY, required=True),
        FieldSpec("payment_method", KIND_TEXT),
    )

    def validate(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        return validate_invoice_totals(self, fields)
