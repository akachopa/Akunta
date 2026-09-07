"""Parser gambar (plan.md §13.1 Image/Vision OCR).

Phase 3 hanya memvalidasi berkas dan merekam dimensinya. OCR-nya sendiri memerlukan
vision model (plan.md §13.2 "Structured extraction → small/vision model"), sehingga
halaman gambar selalu ditandai needs_ocr dan pembacaan isinya diserahkan ke Phase 4.
Menebak isi struk dari metadata gambar akan menjadi prediksi palsu.
"""

from __future__ import annotations

import io

from PIL import Image, UnidentifiedImageError

from app.parsers.base import DocumentParseError, DocumentParser, ParsedPage, ParseResult


class ImageParser(DocumentParser):
    name = "image"
    version = "1.0"
    content_kind = "image"
    extensions = ("jpg", "jpeg", "png")
    mime_types = ("image/jpeg", "image/png")

    def parse(self, content: bytes, filename: str) -> ParseResult:
        try:
            with Image.open(io.BytesIO(content)) as image:
                image.verify()

            # verify() menghabiskan stream, jadi berkas dibuka ulang untuk membaca
            # dimensi dan jumlah frame.
            with Image.open(io.BytesIO(content)) as image:
                width, height = image.size
                image_format = image.format
                frames = getattr(image, "n_frames", 1)
        except (UnidentifiedImageError, OSError, ValueError) as exception:
            raise DocumentParseError(f"Gambar tidak dapat dibaca: {exception}") from exception

        pages = [
            ParsedPage(
                page_number=1,
                kind="image",
                label=filename,
                needs_ocr=True,
                metadata={
                    "width": width,
                    "height": height,
                    "format": image_format,
                    "frames": frames,
                    "ocr_pending_reason": "OCR gambar memerlukan vision model (Phase 4).",
                },
            )
        ]

        return self.result(pages, metadata={"width": width, "height": height})
