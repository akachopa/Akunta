"""Test endpoint /v1/parse (plan.md §13.1, §30)."""

from __future__ import annotations

import io

from fastapi.testclient import TestClient
from openpyxl import Workbook

from app.config import get_settings
from app.main import create_app

client = TestClient(create_app())


def csv_bytes() -> bytes:
    return b"Tanggal,Keterangan,Nominal\n2026-01-15,SETORAN TUNAI,2000000\n"


def xlsx_bytes() -> bytes:
    workbook = Workbook()
    workbook.active.append(["Tanggal", "Nominal"])
    workbook.active.append(["2026-01-15", 2000000])

    buffer = io.BytesIO()
    workbook.save(buffer)

    return buffer.getvalue()


def test_capabilities_lists_supported_extensions() -> None:
    response = client.get("/v1/parse/capabilities")

    assert response.status_code == 200
    assert "pdf" in response.json()["extensions"]


def test_parse_returns_structured_json() -> None:
    response = client.post(
        "/v1/parse",
        files={"file": ("mutasi.csv", csv_bytes(), "text/csv")},
    )

    assert response.status_code == 200

    payload = response.json()
    assert payload["parser"] == "csv"
    assert payload["content_kind"] == "table"
    assert payload["page_count"] == 1
    assert payload["needs_ocr"] is False
    assert payload["pages"][0]["rows"][1] == ["2026-01-15", "SETORAN TUNAI", "2000000"]


def test_parse_accepts_explicit_filename_and_mime_override() -> None:
    """Laravel mengirim nama asli terpisah karena berkas tersimpan dengan nama acak."""
    response = client.post(
        "/v1/parse",
        files={"file": ("9f2c-uuid", xlsx_bytes(), "application/octet-stream")},
        data={
            "filename": "mutasi-januari.xlsx",
            "mime_type": ("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"),
        },
    )

    assert response.status_code == 200
    assert response.json()["parser"] == "spreadsheet"


def test_parse_rejects_unsupported_type_with_415() -> None:
    """415 memisahkan tipe tidak didukung dari berkas rusak, supaya status dokumen tepat."""
    response = client.post(
        "/v1/parse",
        files={"file": ("kontrak.docx", b"apa saja", "application/msword")},
    )

    assert response.status_code == 415
    assert "parser" in response.json()["detail"]


def test_parse_rejects_corrupt_file_with_422() -> None:
    response = client.post(
        "/v1/parse",
        files={"file": ("rusak.pdf", b"bukan pdf", "application/pdf")},
    )

    assert response.status_code == 422


def test_parse_requires_internal_token_when_configured() -> None:
    """plan.md §30: request internal tetap harus terautentikasi."""
    settings = get_settings()
    original = settings.token
    settings.token = "rahasia-internal"

    try:
        unauthorized = client.post(
            "/v1/parse",
            files={"file": ("mutasi.csv", csv_bytes(), "text/csv")},
        )
        assert unauthorized.status_code == 401

        authorized = client.post(
            "/v1/parse",
            files={"file": ("mutasi.csv", csv_bytes(), "text/csv")},
            headers={"Authorization": "Bearer rahasia-internal"},
        )
        assert authorized.status_code == 200
    finally:
        settings.token = original


def test_parse_endpoint_does_not_classify() -> None:
    """Klasifikasi dokumen adalah Phase 4 (plan.md §37 Phase 4)."""
    payload = client.post(
        "/v1/parse",
        files={"file": ("mutasi.csv", csv_bytes(), "text/csv")},
    ).json()

    assert "document_type" not in payload
    assert "classification_confidence" not in payload
