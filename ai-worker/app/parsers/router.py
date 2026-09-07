"""Parser router (plan.md §13.1 FILE TYPE DETECTOR + PARSER ROUTER).

Router memilih parser berdasarkan ekstensi dan MIME type. Keduanya dipakai karena
browser sering mengirim MIME generik seperti application/octet-stream, sementara
ekstensi bisa saja salah.
"""

from __future__ import annotations

from app.parsers.base import DocumentParser, ParseResult, UnsupportedDocument
from app.parsers.csv import CsvParser
from app.parsers.image import ImageParser
from app.parsers.pdf import PdfParser
from app.parsers.spreadsheet import SpreadsheetParser

# Urutan menentukan prioritas. Spreadsheet lebih dulu daripada CSV supaya berkas .xlsx
# yang dikirim dengan MIME text/plain tidak salah masuk ke CSV parser.
_PARSERS: tuple[DocumentParser, ...] = (
    PdfParser(),
    SpreadsheetParser(),
    CsvParser(),
    ImageParser(),
)


def extension_of(filename: str) -> str:
    _, separator, extension = filename.rpartition(".")

    return extension.lower() if separator else ""


def supported_extensions() -> list[str]:
    return sorted({extension for parser in _PARSERS for extension in parser.extensions})


def resolve_parser(filename: str, mime_type: str | None) -> DocumentParser:
    extension = extension_of(filename)

    for parser in _PARSERS:
        if parser.supports(extension, mime_type):
            return parser

    raise UnsupportedDocument(
        f"Tidak ada parser untuk berkas [{filename}] bertipe [{mime_type or 'tidak diketahui'}]. "
        f"Ekstensi yang didukung: {', '.join(supported_extensions())}."
    )


def parse(content: bytes, filename: str, mime_type: str | None = None) -> ParseResult:
    if not content:
        raise UnsupportedDocument("Berkas kosong tidak dapat diparse.")

    return resolve_parser(filename, mime_type).parse(content, filename)
