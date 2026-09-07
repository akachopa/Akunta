"""Extractor settlement QRIS dan e-wallet (plan.md §14.2, §19.2, §28).

Settlement QRIS adalah kasus yang paling mudah salah dibukukan: yang masuk ke rekening
adalah nilai neto, sedangkan pendapatannya adalah nilai bruto dan selisihnya adalah biaya
MDR. plan.md §44.3 melarang menganggap kas masuk sebagai pendapatan, jadi ketiga angka itu
wajib dan hubungannya diperiksa.
"""

from __future__ import annotations

from decimal import Decimal
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


class QrisSettlementExtractor(DocumentExtractor):
    name = "qris"
    version = "1.0"
    document_types = ("qris_settlement", "ewallet_settlement")

    fields = (
        FieldSpec("settlement_date", KIND_DATE, required=True),
        FieldSpec("merchant_id", KIND_TEXT),
        FieldSpec("merchant_name", KIND_TEXT),
        FieldSpec("gross_amount", KIND_MONEY, required=True),
        FieldSpec("mdr_fee", KIND_MONEY, required=True),
        FieldSpec("net_amount", KIND_MONEY, required=True),
        FieldSpec("transaction_count", KIND_INTEGER),
    )

    def validate(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        errors: list[str] = []

        gross = self.money(fields, "gross_amount")
        mdr = self.money(fields, "mdr_fee")
        net = self.money(fields, "net_amount")

        if mdr is not None and mdr < Decimal("0"):
            errors.append("Biaya MDR bernilai negatif.")

        if gross is None or mdr is None or net is None:
            return errors

        if gross - mdr != net:
            errors.append(
                f"Settlement tidak konsisten: bruto {gross} − MDR {mdr} tidak sama dengan "
                f"neto {net}."
            )

        return errors
