"""Parser PDF (plan.md §13.1 PDF Parser)."""

from __future__ import annotations

import io

from pypdf import PdfReader
from pypdf.errors import PdfReadError

from app.parsers.base import DocumentParseError, DocumentParser, ParsedPage, ParseResult

# PDF hasil scan hanya berisi gambar. Ambang di bawah ini memisahkan halaman yang
# benar-benar punya text layer dari halaman yang hanya menghasilkan sisa karakter,
# sehingga halaman scan ditandai needs_ocr dan diserahkan ke vision model pada Phase 4.
MIN_TEXT_LAYER_CHARS = 20


class PdfParser(DocumentParser):
    name = "pdf"
    version = "1.0"
    content_kind = "pdf"
    extensions = ("pdf",)
    mime_types = ("application/pdf",)

    def parse(self, content: bytes, filename: str) -> ParseResult:
        try:
            reader = PdfReader(io.BytesIO(content))
        except (PdfReadError, OSError, ValueError) as exception:
            raise DocumentParseError(f"PDF tidak dapat dibaca: {exception}") from exception

        if reader.is_encrypted:
            # Dekripsi dengan password kosong sering berhasil pada PDF yang hanya
            # dilindungi permission, bukan password baca.
            try:
                reader.decrypt("")
            except Exception as exception:  # noqa: BLE001 - pypdf melempar tipe beragam
                raise DocumentParseError(
                    "PDF terproteksi password dan tidak dapat dibaca."
                ) from exception

        pages: list[ParsedPage] = []

        for index, page in enumerate(reader.pages, start=1):
            try:
                text = page.extract_text() or ""
            except Exception as exception:  # noqa: BLE001 - halaman rusak tidak boleh menggagalkan seluruh berkas
                text = ""
                extraction_error: str | None = str(exception)
            else:
                extraction_error = None

            text = text.strip()
            has_text_layer = len(text) >= MIN_TEXT_LAYER_CHARS

            pages.append(
                ParsedPage(
                    page_number=index,
                    kind="pdf_page",
                    text=text or None,
                    needs_ocr=not has_text_layer,
                    metadata={
                        "char_count": len(text),
                        "has_text_layer": has_text_layer,
                        "extraction_error": extraction_error,
                    },
                )
            )

        if not pages:
            raise DocumentParseError("PDF tidak memiliki halaman.")

        return self.result(
            pages,
            metadata={
                "page_count": len(pages),
                "pdf_version": reader.pdf_header,
            },
        )
