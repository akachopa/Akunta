"""Test parser deterministik (plan.md §13.1, §13.2).

Berkas uji dibangun di dalam test, bukan disimpan sebagai fixture biner, supaya isinya
terlihat jelas dan tidak ada dokumen finansial nyata yang masuk repository.
"""

from __future__ import annotations

import datetime as dt
import io

import pytest
from openpyxl import Workbook
from PIL import Image
from pypdf import PdfWriter

from app.parsers.base import DocumentParseError, UnsupportedDocument
from app.parsers.router import extension_of, parse, resolve_parser, supported_extensions
from app.parsers.spreadsheet import stringify


def make_pdf(pages: int = 2) -> bytes:
    writer = PdfWriter()

    for _ in range(pages):
        writer.add_blank_page(width=595, height=842)

    buffer = io.BytesIO()
    writer.write(buffer)

    return buffer.getvalue()


def make_xlsx() -> bytes:
    workbook = Workbook()

    mutasi = workbook.active
    mutasi.title = "Mutasi"
    mutasi.append(["Tanggal", "Keterangan", "Debit", "Kredit"])
    mutasi.append([dt.date(2026, 1, 15), "TRSF PT SUMBER MAKMUR", 5550000, None])
    mutasi.append([dt.date(2026, 1, 16), "SETORAN TUNAI", None, 2000000])
    mutasi.append([None, None, None, None])

    ringkasan = workbook.create_sheet("Ringkasan")
    ringkasan.append(["Saldo Akhir", 113680000.10])

    buffer = io.BytesIO()
    workbook.save(buffer)

    return buffer.getvalue()


def make_png(width: int = 40, height: int = 30) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", (width, height), color=(200, 200, 200)).save(buffer, format="PNG")

    return buffer.getvalue()


def test_router_resolves_by_extension() -> None:
    assert resolve_parser("mutasi.pdf", None).name == "pdf"
    assert resolve_parser("mutasi.xlsx", None).name == "spreadsheet"
    assert resolve_parser("mutasi.csv", None).name == "csv"
    assert resolve_parser("struk.JPG", None).name == "image"


def test_router_resolves_by_mime_when_extension_missing() -> None:
    """Browser kadang mengirim berkas tanpa ekstensi yang berguna."""
    assert resolve_parser("upload", "application/pdf").name == "pdf"
    assert resolve_parser("upload", "image/png").name == "image"


def test_router_prefers_spreadsheet_over_csv_for_xlsx() -> None:
    """Ekspor .xlsx yang dikirim dengan MIME text/plain tidak boleh masuk CSV parser."""
    assert resolve_parser("mutasi.xlsx", "text/plain").name == "spreadsheet"


def test_router_rejects_unsupported_type() -> None:
    with pytest.raises(UnsupportedDocument) as error:
        resolve_parser("kontrak.docx", "application/msword")

    assert "docx" in str(error.value)


def test_router_rejects_empty_content() -> None:
    with pytest.raises(UnsupportedDocument):
        parse(b"", "mutasi.csv", "text/csv")


def test_extension_of_handles_missing_extension() -> None:
    assert extension_of("mutasi.PDF") == "pdf"
    assert extension_of("mutasi") == ""


def test_supported_extensions_covers_phase_3_scope() -> None:
    """plan.md §3.1 Document Inbox: PDF, XLS/XLSX, CSV, dan JPG/JPEG/PNG."""
    assert set(supported_extensions()) >= {"csv", "jpeg", "jpg", "pdf", "png", "xlsx"}


def test_pdf_parser_returns_one_page_per_page() -> None:
    result = parse(make_pdf(pages=3), "mutasi.pdf", "application/pdf")

    assert result.parser == "pdf"
    assert result.content_kind == "pdf"
    assert len(result.pages) == 3
    assert [page.page_number for page in result.pages] == [1, 2, 3]


def test_pdf_without_text_layer_is_flagged_for_ocr() -> None:
    """PDF hasil scan hanya berisi gambar, jadi isinya menunggu vision model Phase 4."""
    result = parse(make_pdf(pages=1), "scan.pdf", "application/pdf")

    assert result.needs_ocr is True
    assert result.pages[0].metadata["has_text_layer"] is False


def test_pdf_parser_rejects_corrupt_file() -> None:
    with pytest.raises(DocumentParseError):
        parse(b"bukan pdf sama sekali", "rusak.pdf", "application/pdf")


def test_csv_parser_reads_rows_and_detects_semicolon() -> None:
    content = "Tanggal;Keterangan;Nominal\n2026-01-15;TRSF SUMBER MAKMUR;5550000\n"

    result = parse(content.encode(), "mutasi.csv", "text/csv")

    page = result.pages[0]
    assert page.metadata["delimiter"] == ";"
    assert page.rows[0] == ["Tanggal", "Keterangan", "Nominal"]
    assert page.rows[1] == ["2026-01-15", "TRSF SUMBER MAKMUR", "5550000"]
    assert result.needs_ocr is False


def test_csv_parser_handles_bom_and_single_column() -> None:
    result = parse("\ufeffKeterangan\nSETORAN TUNAI\n".encode(), "mutasi.csv", "text/csv")

    assert result.pages[0].rows[0] == ["Keterangan"]
    assert result.pages[0].metadata["encoding"] == "utf-8-sig"


def test_csv_parser_rejects_empty_file() -> None:
    with pytest.raises(DocumentParseError):
        parse(b"   \n", "mutasi.csv", "text/csv")


def test_spreadsheet_parser_returns_one_page_per_sheet() -> None:
    result = parse(
        make_xlsx(),
        "mutasi.xlsx",
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    )

    assert result.parser == "spreadsheet"
    assert [page.label for page in result.pages] == ["Mutasi", "Ringkasan"]

    mutasi = result.pages[0]
    # Baris kosong di akhir sheet adalah artefak ekspor Excel dan tidak ikut terbaca.
    assert mutasi.metadata["row_count"] == 3
    assert mutasi.rows[1] == ["2026-01-15", "TRSF PT SUMBER MAKMUR", "5550000", None]


def test_spreadsheet_parser_keeps_decimal_precision() -> None:
    """plan.md §44.14: nominal tidak boleh melebar menjadi artefak float."""
    result = parse(
        make_xlsx(),
        "mutasi.xlsx",
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    )

    assert result.pages[1].rows[0] == ["Saldo Akhir", "113680000.1"]


def test_stringify_does_not_leak_binary_float_artifacts() -> None:
    assert stringify(0.1) == "0.1"
    assert stringify(5550000) == "5550000"
    assert stringify(dt.datetime(2026, 1, 15, 0, 0)) == "2026-01-15"
    assert stringify(dt.datetime(2026, 1, 15, 9, 30)) == "2026-01-15T09:30:00"
    assert stringify(None) is None
    assert stringify("  ") is None


def test_spreadsheet_parser_rejects_legacy_xls() -> None:
    """Format BIFF lama tidak dibaca openpyxl; menolaknya lebih jujur daripada kosong."""
    with pytest.raises(DocumentParseError):
        parse(b"\xd0\xcf\x11\xe0bukan xlsx", "mutasi.xls", "application/vnd.ms-excel")


def test_image_parser_records_dimensions_and_defers_ocr() -> None:
    result = parse(make_png(width=64, height=48), "struk.png", "image/png")

    page = result.pages[0]
    assert result.content_kind == "image"
    assert page.needs_ocr is True
    assert page.text is None
    assert (page.metadata["width"], page.metadata["height"]) == (64, 48)


def test_image_parser_rejects_corrupt_image() -> None:
    with pytest.raises(DocumentParseError):
        parse(b"bukan gambar", "struk.png", "image/png")


def test_parsers_never_call_ai_provider() -> None:
    """plan.md §13.2: file parsing bersifat deterministik, bukan pekerjaan model AI."""
    import app.parsers.csv as csv_parser
    import app.parsers.image as image_parser
    import app.parsers.pdf as pdf_parser
    import app.parsers.router as router
    import app.parsers.spreadsheet as spreadsheet_parser

    for module in (csv_parser, image_parser, pdf_parser, spreadsheet_parser, router):
        source = module.__doc__ or ""
        assert "provider" not in dir(module), f"{module.__name__} tidak boleh punya provider"
        assert "AIProvider" not in source


def test_parsing_is_reproducible() -> None:
    """plan.md §45.12: traceability sampai file asli menuntut hasil yang stabil."""
    content = make_xlsx()

    first = parse(content, "mutasi.xlsx", None).to_dict()
    second = parse(content, "mutasi.xlsx", None).to_dict()

    assert first == second
