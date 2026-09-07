"""Kontrak parser dokumen (plan.md §13.1 PARSER ROUTER, §28).

plan.md §13.2 menetapkan file parsing sebagai *deterministic parser*, bukan pekerjaan
model AI. Karena itu tidak ada parser di paket ini yang boleh memanggil provider AI:
hasilnya harus dapat direproduksi byte-per-byte untuk berkas yang sama, supaya
traceability sampai file asli tetap terjaga (plan.md §45.12).
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any


class UnsupportedDocument(Exception):
    """Berkas tidak dapat ditangani parser mana pun.

    Dibedakan dari kegagalan parsing: berkas yang tidak didukung bukan error sistem,
    melainkan kondisi yang harus ditampilkan ke user sebagai UNSUPPORTED (plan.md §5.3).
    """


class DocumentParseError(Exception):
    """Berkas seharusnya didukung tetapi gagal dibaca, misalnya karena korup."""


@dataclass
class ParsedPage:
    """Satu unit isi dokumen.

    Untuk PDF satu halaman, untuk spreadsheet satu sheet, dan untuk gambar satu berkas.
    `rows` hanya terisi pada sumber tabular; `text` hanya terisi bila teks memang dapat
    diambil secara deterministik.
    """

    page_number: int
    kind: str
    label: str | None = None
    text: str | None = None
    rows: list[list[str | None]] | None = None
    needs_ocr: bool = False
    metadata: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        return {
            "page_number": self.page_number,
            "kind": self.kind,
            "label": self.label,
            "text": self.text,
            "rows": self.rows,
            "needs_ocr": self.needs_ocr,
            "metadata": self.metadata,
        }


@dataclass
class ParseResult:
    parser: str
    parser_version: str
    content_kind: str
    pages: list[ParsedPage]
    metadata: dict[str, Any] = field(default_factory=dict)

    @property
    def needs_ocr(self) -> bool:
        return any(page.needs_ocr for page in self.pages)

    def to_dict(self) -> dict[str, Any]:
        return {
            "parser": self.parser,
            "parser_version": self.parser_version,
            "content_kind": self.content_kind,
            "page_count": len(self.pages),
            "needs_ocr": self.needs_ocr,
            "pages": [page.to_dict() for page in self.pages],
            "metadata": self.metadata,
        }


class DocumentParser(ABC):
    name: str
    version: str
    content_kind: str
    extensions: tuple[str, ...]
    mime_types: tuple[str, ...]

    def supports(self, extension: str, mime_type: str | None) -> bool:
        if extension.lower().lstrip(".") in self.extensions:
            return True

        return mime_type is not None and mime_type.lower() in self.mime_types

    @abstractmethod
    def parse(self, content: bytes, filename: str) -> ParseResult:
        """Membaca isi berkas menjadi halaman-halaman terstruktur."""

    def result(
        self,
        pages: list[ParsedPage],
        metadata: dict[str, Any] | None = None,
    ) -> ParseResult:
        return ParseResult(
            parser=self.name,
            parser_version=self.version,
            content_kind=self.content_kind,
            pages=pages,
            metadata=metadata or {},
        )
