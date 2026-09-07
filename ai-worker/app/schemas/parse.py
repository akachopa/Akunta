"""Schema response parsing dokumen (plan.md §45.8 structured output)."""

from typing import Any

from pydantic import BaseModel, Field


class ParsedPageResponse(BaseModel):
    page_number: int
    kind: str
    label: str | None = None
    text: str | None = None
    rows: list[list[str | None]] | None = None
    needs_ocr: bool = False
    metadata: dict[str, Any] = Field(default_factory=dict)


class ParseResponse(BaseModel):
    parser: str
    parser_version: str
    content_kind: str
    page_count: int

    # True bila ada halaman yang isinya hanya dapat dibaca lewat OCR. Laravel memakai
    # penanda ini untuk memutuskan bahwa dokumen menunggu vision model (Phase 4).
    needs_ocr: bool = False

    pages: list[ParsedPageResponse] = Field(default_factory=list)
    metadata: dict[str, Any] = Field(default_factory=dict)
