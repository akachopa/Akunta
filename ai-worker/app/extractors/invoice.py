"""Extractor faktur (plan.md §14.2 extract_invoice).

Mencakup faktur pembelian, faktur penjualan, tagihan iklan, dan tagihan utilitas. Keempatnya
memakai schema yang sama karena bentuk dokumennya identik: ada penerbit, nomor, tanggal,
dan total yang harus dibayar. Yang membedakan artinya secara akuntansi adalah arah
transaksinya, dan itu ditentukan classifier, bukan extractor.
"""

from __future__ import annotations

from decimal import Decimal
from typing import Any

from app.extractors.base import (
    KIND_DATE,
    KIND_MONEY,
    KIND_TEXT,
    DocumentExtractor,
    ExtractedField,
    FieldSpec,
)


class InvoiceExtractor(DocumentExtractor):
    name = "invoice"
    version = "1.0"
    document_types = (
        "purchase_invoice",
        "sales_invoice",
        "advertising_invoice",
        "utility_bill",
    )

    fields = (
        FieldSpec("document_number", KIND_TEXT, required=True),
        FieldSpec("document_date", KIND_DATE, required=True),
        FieldSpec("due_date", KIND_DATE),
        FieldSpec("issuer_name", KIND_TEXT, required=True),
        FieldSpec("buyer_name", KIND_TEXT),
        FieldSpec("currency", KIND_TEXT),
        FieldSpec("subtotal", KIND_MONEY),
        FieldSpec("tax", KIND_MONEY),
        FieldSpec("total", KIND_MONEY, required=True),
    )

    def validate(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        return validate_invoice_totals(self, fields)


def validate_invoice_totals(
    extractor: DocumentExtractor,
    fields: list[ExtractedField],
) -> list[str]:
    """Memeriksa aritmetika subtotal + pajak = total.

    Pemeriksaan ini adalah pertahanan utama terhadap ekstraksi yang tampak lengkap tetapi
    salah membaca angka. plan.md §37 Phase 4 menuntut output invalid tidak masuk transaction
    pipeline, dan total yang tidak konsisten dengan komponennya adalah kasus paling sering:
    satu digit yang salah baca menghasilkan jurnal yang tidak dapat direkonsiliasi.

    Perbandingan memakai Decimal, bukan float, sesuai plan.md §44.14.
    """

    errors: list[str] = []

    total = extractor.money(fields, "total")
    subtotal = extractor.money(fields, "subtotal")
    tax = extractor.money(fields, "tax")

    if total is not None and total < Decimal("0"):
        errors.append("Total dokumen bernilai negatif.")

    if total is None or subtotal is None or tax is None:
        return errors

    if subtotal + tax != total:
        errors.append(
            f"Total tidak konsisten: subtotal {subtotal} + pajak {tax} tidak sama dengan "
            f"total {total}."
        )

    return errors
