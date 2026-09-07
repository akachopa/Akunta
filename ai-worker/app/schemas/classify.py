"""Schema request dan response klasifikasi dokumen (plan.md §14.1, §45.8)."""

from typing import Any

from pydantic import BaseModel, Field


class ParsedPageInput(BaseModel):
    """Halaman hasil parse yang dikirim Laravel.

    Worker tidak membaca ulang berkas aslinya saat klasifikasi: halaman sudah tersimpan di
    Laravel sejak tahap parse, dan mengirim teksnya jauh lebih murah daripada mengirim
    berkas serta memparse ulang.
    """

    page_number: int = 1
    kind: str | None = None
    label: str | None = None
    text: str | None = None
    rows: list[list[str | None]] | None = None


class ClassifyRequest(BaseModel):
    filename: str
    mime_type: str | None = None

    # Nama bisnis. plan.md §14.1 menyertakannya sebagai business_context, dan tanpa itu
    # faktur penjualan tidak dapat dipisahkan dari faktur pembelian.
    business_context: str | None = None

    provider: str | None = None
    pages: list[ParsedPageInput] = Field(default_factory=list)


class UsageResponse(BaseModel):
    input_tokens: int = 0
    output_tokens: int = 0

    # String desimal, bukan float: biaya tetap uang meski satuannya dolar (plan.md §44.14).
    cost: str = "0"
    currency: str = "USD"


class ClassificationAlternative(BaseModel):
    document_type: str
    confidence: float


class ClassifyResponse(BaseModel):
    document_type: str
    confidence: float
    reason: str | None = None
    alternatives: list[ClassificationAlternative] = Field(default_factory=list)

    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: UsageResponse

    # Output provider sebelum diolah. Disertakan supaya Laravel dapat menyimpannya sebagai
    # raw evidence (plan.md §37 Phase 4 "raw + parsed evidence tersedia").
    raw: dict[str, Any] = Field(default_factory=dict)
