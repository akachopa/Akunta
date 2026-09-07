"""Extractor rekening koran (plan.md §14.2, §28).

Rekening koran adalah satu-satunya jenis dokumen pada Phase 4 yang isi utamanya adalah
baris transaksi, bukan sekumpulan field. Baris tersebut menjadi bahan Phase 5 (transaction
normalization), jadi di sini baris hanya dibaca dan diperiksa konsistensinya, tidak diubah
menjadi transaksi.
"""

from __future__ import annotations

from decimal import Decimal, InvalidOperation
from typing import Any

from app.extractors.base import (
    KIND_DATE,
    KIND_MONEY,
    KIND_TEXT,
    DocumentExtractor,
    ExtractedField,
    FieldSpec,
)


class BankStatementExtractor(DocumentExtractor):
    name = "bank_statement"
    version = "1.0"
    document_types = ("bank_statement",)
    with_rows = True

    fields = (
        FieldSpec("bank_name", KIND_TEXT),
        FieldSpec("account_number", KIND_TEXT, required=True),
        FieldSpec("account_holder", KIND_TEXT),
        FieldSpec("period_start", KIND_DATE),
        FieldSpec("period_end", KIND_DATE),
        FieldSpec("opening_balance", KIND_MONEY, required=True),
        FieldSpec("closing_balance", KIND_MONEY, required=True),
    )

    def validate(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        errors: list[str] = []

        if not rows:
            errors.append("Rekening koran tidak menghasilkan satu pun baris mutasi.")

            return errors

        undated = sum(1 for row in rows if row.get("date") is None)

        if undated > 0:
            errors.append(f"Ada {undated} baris mutasi tanpa tanggal yang dapat dibaca.")

        errors += self._validate_running_balance(fields, rows)

        return errors

    def _validate_running_balance(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        """Memeriksa saldo awal + kredit − debit = saldo akhir.

        Ini pemeriksaan paling bernilai pada seluruh Phase 4: bila identitas ini tidak
        terpenuhi, ada baris yang terlewat atau angka yang salah baca, dan meneruskannya ke
        pipeline akan menghasilkan kas yang tidak pernah dapat direkonsiliasi (plan.md §19.1).
        """

        opening = self.money(fields, "opening_balance")
        closing = self.money(fields, "closing_balance")

        if opening is None or closing is None:
            return []

        debit = self._sum(rows, "debit")
        credit = self._sum(rows, "credit")

        expected = opening + credit - debit

        if expected != closing:
            return [
                f"Saldo tidak konsisten: saldo awal {opening} + kredit {credit} − debit "
                f"{debit} menghasilkan {expected}, sedangkan saldo akhir tertulis {closing}."
            ]

        return []

    def _sum(self, rows: list[dict[str, Any]], key: str) -> Decimal:
        total = Decimal("0")

        for row in rows:
            raw = row.get(key)

            if raw is None:
                continue

            try:
                total += Decimal(str(raw))
            except InvalidOperation:
                continue

        return total
