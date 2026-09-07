"""Schema request dan response ekstraksi terstruktur (plan.md §14.2, §45.8)."""

from typing import Any

from pydantic import BaseModel, Field

from app.schemas.classify import ParsedPageInput, UsageResponse


class ExtractRequest(BaseModel):
    document_type: str
    business_context: str | None = None
    provider: str | None = None
    pages: list[ParsedPageInput] = Field(default_factory=list)


class ExtractedFieldResponse(BaseModel):
    key: str
    kind: str

    # Selalu string atau null. Angka dan tanggal tidak dikirim sebagai tipe native supaya
    # presisi uang tidak hilang di JSON (plan.md §44.14).
    value: str | None = None

    confidence: float = 0.0

    # Asal nilai pada dokumen. plan.md §45.10 dan §45.12 mewajibkan evidence sampai ke
    # sumbernya, dan tanpa dua field ini reviewer tidak dapat memverifikasi angka.
    page_number: int | None = None
    source_text: str | None = None


class StatementRowResponse(BaseModel):
    date: str | None = None
    description: str | None = None
    debit: str | None = None
    credit: str | None = None
    balance: str | None = None
    page_number: int | None = None
    source_text: str | None = None


class ExtractResponse(BaseModel):
    document_type: str
    extractor: str
    schema_version: str

    # False bila ada field wajib yang hilang atau aritmetika dokumen tidak konsisten.
    # Laravel wajib memeriksa penanda ini: output invalid tidak boleh masuk transaction
    # pipeline (plan.md §37 Phase 4).
    valid: bool
    validation_errors: list[str] = Field(default_factory=list)

    confidence: float = 0.0
    fields: list[ExtractedFieldResponse] = Field(default_factory=list)
    rows: list[StatementRowResponse] = Field(default_factory=list)

    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: UsageResponse

    raw: dict[str, Any] = Field(default_factory=dict)
