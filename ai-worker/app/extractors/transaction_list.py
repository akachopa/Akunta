"""Extractor daftar transaksi tabular (plan.md §14.2, §28, §37 Phase 5).

Laporan POS dan laporan marketplace bukan dokumen bernilai tunggal: isinya daftar
transaksi, sama seperti rekening koran. Bedanya kolom nilainya tunggal dan bertanda —
penjualan positif, potongan atau refund negatif — bukan pasangan debit dan kredit.

Extractor ini menjadi sumber "spreadsheet transaction parser" pada plan.md §37 Phase 5.
Ia hanya membaca dan memeriksa konsistensi barisnya; mengubah baris menjadi transaksi
adalah pekerjaan normalizer di Laravel.
"""

from __future__ import annotations

from decimal import Decimal, InvalidOperation
from typing import Any

from app.extractors.base import (
    KIND_DATE,
    KIND_INTEGER,
    KIND_MONEY,
    KIND_TEXT,
    DocumentExtractor,
    ExtractedField,
    FieldSpec,
)


class TransactionListExtractor(DocumentExtractor):
    name = "transaction_list"
    version = "1.0"
    document_types = ("pos_report", "marketplace_report", "consignment_report")
    with_rows = True

    row_fields = (
        FieldSpec("date", KIND_DATE),
        FieldSpec("description", KIND_TEXT),
        FieldSpec("amount", KIND_MONEY),
    )

    fields = (
        FieldSpec("document_date", KIND_DATE),
        FieldSpec("period_start", KIND_DATE),
        FieldSpec("period_end", KIND_DATE),
        FieldSpec("merchant_name", KIND_TEXT),
        FieldSpec("currency", KIND_TEXT),
        FieldSpec("total", KIND_MONEY),
        FieldSpec("transaction_count", KIND_INTEGER),
    )

    def validate(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        """Nilai dokumen ini ada pada barisnya, jadi barisnyalah yang wajib konsisten.

        Tidak ada field tingkat dokumen yang diwajibkan: laporan penjualan sering hanya
        berupa tabel tanpa blok identitas. Yang tidak boleh terjadi adalah baris tanpa
        tanggal atau tanpa nominal, karena baris seperti itu tidak dapat menjadi transaksi
        dan membuangnya diam-diam berarti kehilangan penjualan tanpa jejak.
        """

        if not rows:
            return ["Dokumen tidak menghasilkan satu pun baris transaksi."]

        errors: list[str] = []

        undated = sum(1 for row in rows if row.get("date") is None)

        if undated > 0:
            errors.append(f"Ada {undated} baris tanpa tanggal yang dapat dibaca.")

        empty = sum(1 for row in rows if self._amount(row) is None)

        if empty > 0:
            errors.append(f"Ada {empty} baris tanpa nominal yang dapat dibaca.")

        zero = sum(
            1 for row in rows if (value := self._amount(row)) is not None and value == Decimal("0")
        )

        if zero > 0:
            errors.append(f"Ada {zero} baris bernilai nol.")

        errors += self._validate_total(fields, rows)

        return errors

    def _validate_total(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        """Total laporan harus sama dengan jumlah barisnya, bila totalnya tertulis.

        Selisihnya berarti ada baris yang tidak terbaca. Meneruskannya akan menghasilkan
        omzet yang lebih kecil daripada yang sebenarnya, dan kekurangan itu tidak akan
        pernah muncul sebagai kesalahan di laporan mana pun.
        """

        total = self.money(fields, "total")

        if total is None:
            return []

        summed = Decimal("0")

        for row in rows:
            value = self._amount(row)

            if value is not None:
                summed += value

        if summed != total:
            return [
                f"Total tidak konsisten: jumlah baris {summed} tidak sama dengan total "
                f"tertulis {total}."
            ]

        return []

    def _amount(self, row: dict[str, Any]) -> Decimal | None:
        raw = row.get("amount")

        if raw is None:
            return None

        try:
            return Decimal(str(raw))
        except InvalidOperation:
            return None
