"""Kontrak extractor dokumen (plan.md §13.1 STRUCTURED EXTRACTION, §14.2, §28).

Setiap extractor memiliki tiga tanggung jawab dan tidak lebih:

1. mendefinisikan field yang dicari beserta tipenya;
2. menyusun JSON schema yang mengikat output provider (plan.md §45.8);
3. memvalidasi hasilnya, termasuk aritmetika dokumen.

Yang sengaja tidak menjadi tanggung jawab extractor: menebak nilai yang tidak tertulis.
plan.md §14.2 menetapkan field yang tidak ada bernilai null, dan menghitung sendiri nilai
yang hilang akan menghasilkan angka yang tampak sah tetapi tidak punya bukti di dokumen.

Uang diperlakukan sebagai string desimal dari ujung ke ujung (plan.md §44.14). Tidak ada
titik pada modul ini yang mengubahnya menjadi float, termasuk saat memeriksa aritmetika:
perbandingan memakai Decimal.
"""

from __future__ import annotations

from abc import ABC
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation
from typing import Any

from app.numbers import money_to_string, parse_date
from app.providers.base import AIProviderInterface, ProviderUsage

FieldKind = str

KIND_TEXT: FieldKind = "text"
KIND_MONEY: FieldKind = "money"
KIND_DATE: FieldKind = "date"
KIND_INTEGER: FieldKind = "integer"


@dataclass(frozen=True)
class FieldSpec:
    key: str
    kind: FieldKind
    required: bool = False


@dataclass
class ExtractedField:
    key: str
    kind: FieldKind
    value: str | None
    confidence: float
    page_number: int | None = None
    source_text: str | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "key": self.key,
            "kind": self.kind,
            "value": self.value,
            "confidence": self.confidence,
            "page_number": self.page_number,
            "source_text": self.source_text,
        }


@dataclass
class ExtractionOutcome:
    document_type: str
    extractor: str
    schema_version: str
    valid: bool
    confidence: float
    fields: list[ExtractedField]
    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: ProviderUsage
    raw: dict[str, Any]
    rows: list[dict[str, Any]] = field(default_factory=list)
    validation_errors: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return {
            "document_type": self.document_type,
            "extractor": self.extractor,
            "schema_version": self.schema_version,
            "valid": self.valid,
            "confidence": self.confidence,
            "fields": [item.to_dict() for item in self.fields],
            "rows": self.rows,
            "validation_errors": self.validation_errors,
            "provider": self.provider,
            "model": self.model,
            "prompt_version": self.prompt_version,
            "latency_ms": self.latency_ms,
            "usage": self.usage.to_dict(),
            "raw": self.raw,
        }


class DocumentExtractor(ABC):
    name: str
    version: str
    document_types: tuple[str, ...]
    fields: tuple[FieldSpec, ...]

    # True untuk dokumen yang isi utamanya adalah tabel baris transaksi, seperti rekening
    # koran. Baris disimpan terpisah dari field karena jumlahnya tidak tetap.
    with_rows: bool = False

    # Kolom yang dicari pada setiap baris. Default-nya bentuk mutasi rekening; dokumen
    # daftar transaksi seperti laporan POS menimpanya dengan kolomnya sendiri, karena
    # spreadsheet penjualan memuat satu kolom nominal, bukan pasangan debit dan kredit.
    row_fields: tuple[FieldSpec, ...] = (
        FieldSpec("date", KIND_DATE),
        FieldSpec("description", KIND_TEXT),
        FieldSpec("debit", KIND_MONEY),
        FieldSpec("credit", KIND_MONEY),
        FieldSpec("balance", KIND_MONEY),
    )

    def row_keys(self) -> list[str]:
        return [spec.key for spec in self.row_fields]

    def field_keys(self) -> list[str]:
        return [spec.key for spec in self.fields]

    def required_keys(self) -> list[str]:
        return [spec.key for spec in self.fields if spec.required]

    def spec(self, key: str) -> FieldSpec | None:
        for candidate in self.fields:
            if candidate.key == key:
                return candidate

        return None

    def json_schema(self) -> dict[str, Any]:
        """Schema output yang dikirim ke provider (plan.md §45.8).

        Setiap field adalah objek bernilai `value`, `confidence`, `page_number`, dan
        `source_text`, bukan nilai polos. Bentuk itu memaksa provider menyertakan bukti
        asal setiap angka, yang diwajibkan plan.md §45.10 dan §45.12.
        """

        field_schema = {
            "type": ["object", "null"],
            "additionalProperties": False,
            "required": ["value", "confidence", "page_number", "source_text"],
            "properties": {
                "value": {"type": ["string", "null"]},
                "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                "page_number": {"type": ["integer", "null"], "minimum": 1},
                "source_text": {"type": ["string", "null"]},
            },
        }

        schema: dict[str, Any] = {
            "type": "object",
            "additionalProperties": False,
            "required": ["fields"],
            "properties": {
                "fields": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": self.field_keys(),
                    "properties": dict.fromkeys(self.field_keys(), field_schema),
                },
            },
        }

        if self.with_rows:
            row_properties: dict[str, Any] = dict.fromkeys(
                self.row_keys(), {"type": ["string", "null"]}
            )
            row_properties["page_number"] = {"type": ["integer", "null"], "minimum": 1}
            row_properties["source_text"] = {"type": ["string", "null"]}

            schema["required"].append("rows")
            schema["properties"]["rows"] = {
                "type": "array",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": self.row_keys(),
                    "properties": row_properties,
                },
            }

        return schema

    def extract(
        self,
        *,
        pages: list[dict[str, Any]],
        document_type: str,
        business_context: str | None = None,
        provider: AIProviderInterface | None = None,
    ) -> ExtractionOutcome:
        from app.providers.registry import resolve_for_task

        engine = provider or resolve_for_task("extract_document")

        result = engine.extract_document(
            {
                "document_type": document_type,
                "fields": self.field_keys(),
                "pages": pages,
                "business_context": business_context,
                "with_rows": self.with_rows,
                "row_fields": self.row_keys(),
            },
            self.json_schema(),
            self.version,
            document_type,
        )

        fields, coercion_errors = self._coerce_fields(result.data.get("fields"))
        rows = self._coerce_rows(result.data.get("rows")) if self.with_rows else []

        errors = coercion_errors
        errors += self._missing_required(fields)
        errors += self.validate(fields, rows)

        return ExtractionOutcome(
            document_type=document_type,
            extractor=self.name,
            schema_version=self.version,
            valid=errors == [],
            confidence=self._confidence(fields),
            fields=fields,
            rows=rows,
            validation_errors=errors,
            provider=result.provider,
            model=result.model,
            prompt_version=result.prompt_version,
            latency_ms=result.latency_ms,
            usage=result.usage,
            raw=result.data,
        )

    def validate(
        self,
        fields: list[ExtractedField],
        rows: list[dict[str, Any]],
    ) -> list[str]:
        """Pemeriksaan tambahan khas jenis dokumen. Default-nya tidak ada."""

        return []

    def money(self, fields: list[ExtractedField], key: str) -> Decimal | None:
        for item in fields:
            if item.key == key and item.value is not None:
                try:
                    return Decimal(item.value)
                except InvalidOperation:
                    return None

        return None

    def _coerce_fields(self, raw: Any) -> tuple[list[ExtractedField], list[str]]:
        if not isinstance(raw, dict):
            return [], ["Provider tidak mengembalikan objek `fields`."]

        fields: list[ExtractedField] = []
        errors: list[str] = []

        for spec in self.fields:
            entry = raw.get(spec.key)

            if entry is None:
                fields.append(ExtractedField(spec.key, spec.kind, None, 0.0))

                continue

            if not isinstance(entry, dict):
                errors.append(f"Field [{spec.key}] tidak berbentuk objek bernilai.")
                fields.append(ExtractedField(spec.key, spec.kind, None, 0.0))

                continue

            value, error = self._coerce_value(spec, entry.get("value"))

            if error is not None:
                errors.append(error)

            fields.append(
                ExtractedField(
                    key=spec.key,
                    kind=spec.kind,
                    value=value,
                    confidence=self._field_confidence(entry.get("confidence"), value),
                    page_number=self._page_number(entry.get("page_number")),
                    source_text=(
                        entry.get("source_text")
                        if isinstance(entry.get("source_text"), str)
                        else None
                    ),
                )
            )

        return fields, errors

    def _coerce_value(self, spec: FieldSpec, raw: Any) -> tuple[str | None, str | None]:
        if raw is None:
            return None, None

        if not isinstance(raw, str):
            raw = str(raw)

        candidate = raw.strip()

        if candidate == "":
            return None, None

        if spec.kind == KIND_MONEY:
            try:
                return money_to_string(Decimal(candidate)), None
            except InvalidOperation:
                return None, f"Nilai uang pada field [{spec.key}] bukan desimal: [{candidate}]."

        if spec.kind == KIND_DATE:
            parsed = parse_date(candidate)

            if parsed is None:
                return None, f"Tanggal pada field [{spec.key}] tidak dapat dibaca: [{candidate}]."

            return parsed.isoformat(), None

        if spec.kind == KIND_INTEGER:
            try:
                return str(int(Decimal(candidate))), None
            except (InvalidOperation, ValueError):
                return None, f"Field [{spec.key}] bukan bilangan bulat: [{candidate}]."

        return candidate, None

    def _field_confidence(self, raw: Any, value: str | None) -> float:
        if value is None:
            return 0.0

        if isinstance(raw, bool) or not isinstance(raw, (int, float)):
            return 0.0

        return round(min(max(float(raw), 0.0), 1.0), 4)

    def _page_number(self, raw: Any) -> int | None:
        if isinstance(raw, bool) or not isinstance(raw, int):
            return None

        return raw if raw >= 1 else None

    def _coerce_rows(self, raw: Any) -> list[dict[str, Any]]:
        if not isinstance(raw, list):
            return []

        rows: list[dict[str, Any]] = []

        for entry in raw:
            if not isinstance(entry, dict):
                continue

            row: dict[str, Any] = {}

            for spec in self.row_fields:
                row[spec.key] = self._row_value(spec, entry.get(spec.key))

            row["page_number"] = self._page_number(entry.get("page_number"))
            row["source_text"] = (
                entry.get("source_text") if isinstance(entry.get("source_text"), str) else None
            )

            rows.append(row)

        return rows

    def _row_value(self, spec: FieldSpec, raw: Any) -> str | None:
        if spec.kind == KIND_MONEY:
            return self._row_money(raw)

        if spec.kind == KIND_DATE:
            parsed = parse_date(str(raw or ""))

            return parsed.isoformat() if parsed is not None else None

        return str(raw or "").strip() or None

    def _row_money(self, raw: Any) -> str | None:
        if raw is None:
            return None

        try:
            return money_to_string(Decimal(str(raw).strip()))
        except InvalidOperation:
            return None

    def _missing_required(self, fields: list[ExtractedField]) -> list[str]:
        found = {item.key for item in fields if item.value is not None}

        return [
            f"Field wajib [{key}] tidak ditemukan pada dokumen."
            for key in self.required_keys()
            if key not in found
        ]

    def _confidence(self, fields: list[ExtractedField]) -> float:
        """Confidence ekstraksi sebagai satu angka (plan.md §15.1 `extraction_confidence`).

        Nilainya adalah rata-rata confidence field yang ditemukan, dikalikan proporsi field
        wajib yang berhasil terisi. Perkalian itu penting: dokumen yang hanya menghasilkan
        separuh field wajib tidak boleh terlihat meyakinkan hanya karena field yang sedikit
        itu terbaca jelas.
        """

        required = self.required_keys()
        found = [item for item in fields if item.value is not None]

        if not found:
            return 0.0

        mean = sum(item.confidence for item in found) / len(found)

        if not required:
            return round(mean, 4)

        satisfied = sum(1 for item in found if item.key in required)

        return round(mean * (satisfied / len(required)), 4)
