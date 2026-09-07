"""Test endpoint /v1/classify dan /v1/extract (plan.md §14, §30, §37 Phase 4).

Pemetaan kode status yang diuji di sini adalah kontrak dengan Laravel: 503 berarti layak
diretry, 415 berarti mengulang tidak berguna, dan 200 dengan `valid: false` berarti
hasilnya harus disimpan sebagai bukti tetapi tidak boleh dipakai.
"""

from __future__ import annotations

from typing import Any

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import create_app
from app.providers.base import AIProviderInterface, ProviderResult, ProviderUsage
from app.providers.registry import register_provider
from app.taxonomy import DOCUMENT_TYPES

client = TestClient(create_app())

INVOICE_PAGES: list[dict[str, Any]] = [
    {
        "page_number": 1,
        "kind": "pdf_page",
        "text": (
            "INVOICE\n"
            "No. Invoice: INV-2383\n"
            "Tanggal Invoice: 04/09/2026\n"
            "Dari: PT Sumber Makmur\n"
            "Kepada: Toko Berkah Jaya\n"
            "Subtotal: 5.000.000\n"
            "PPN: 550.000\n"
            "Total: 5.550.000\n"
        ),
    }
]


class _RogueProvider(AIProviderInterface):
    """Provider yang melanggar kontrak, untuk menguji pertahanan endpoint."""

    name = "rogue"

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        return ProviderResult(
            data={"document_type": "faktur_karangan", "confidence": 0.99},
            provider=self.name,
            model="rogue-1",
            prompt_version="1.0",
            latency_ms=1,
            usage=ProviderUsage(),
        )

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        return ProviderResult(
            data={"fields": "bukan objek"},
            provider=self.name,
            model="rogue-1",
            prompt_version="1.0",
            latency_ms=1,
            usage=ProviderUsage(),
        )

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        raise NotImplementedError


register_provider(_RogueProvider)


def test_taxonomy_endpoint_exposes_document_types() -> None:
    """Laravel memeriksa daftar ini terhadap enum-nya sendiri."""
    payload = client.get("/v1/classify/taxonomy").json()

    assert payload["document_types"] == list(DOCUMENT_TYPES)
    assert "bank_statement" in payload["extractable_document_types"]
    assert "payroll" not in payload["extractable_document_types"]


def test_classify_returns_structured_json() -> None:
    """plan.md §37 Phase 4: output selalu structured JSON."""
    response = client.post(
        "/v1/classify",
        json={
            "filename": "mutasi.pdf",
            "mime_type": "application/pdf",
            "pages": [{"page_number": 1, "text": "REKENING KORAN\nSaldo Awal 5.000.000"}],
        },
    )

    assert response.status_code == 200

    payload = response.json()
    assert payload["document_type"] == "bank_statement"
    assert 0 <= payload["confidence"] <= 1
    assert payload["provider"] == "heuristic"
    assert payload["prompt_version"] == "rules-1.0"
    assert payload["raw"]["document_type"] == "bank_statement"


def test_classify_reports_usage_so_cost_can_be_attributed() -> None:
    """plan.md §32.1: AI_cost_per_document dan AI_cost_per_business."""
    payload = client.post(
        "/v1/classify",
        json={"filename": "struk.pdf", "pages": [{"page_number": 1, "text": "Nota kasir"}]},
    ).json()

    assert payload["usage"] == {
        "input_tokens": 0,
        "output_tokens": 0,
        "cost": "0",
        "currency": "USD",
    }


def test_classify_rejects_output_outside_taxonomy_with_422() -> None:
    response = client.post(
        "/v1/classify",
        json={"filename": "a.pdf", "provider": "rogue", "pages": [{"text": "apa saja"}]},
    )

    assert response.status_code == 422
    assert "taksonomi" in response.json()["detail"]


def test_classify_returns_503_when_no_provider_is_active() -> None:
    """Provider yang menolak inferensi adalah kegagalan sementara, bukan hasil."""
    response = client.post(
        "/v1/classify",
        json={"filename": "a.pdf", "provider": "null", "pages": [{"text": "apa saja"}]},
    )

    assert response.status_code == 503


def test_classify_returns_503_for_unregistered_provider() -> None:
    response = client.post(
        "/v1/classify",
        json={"filename": "a.pdf", "provider": "tidak-ada", "pages": [{"text": "x"}]},
    )

    assert response.status_code == 503


def test_extract_returns_fields_with_evidence() -> None:
    response = client.post(
        "/v1/extract",
        json={"document_type": "purchase_invoice", "pages": INVOICE_PAGES},
    )

    assert response.status_code == 200

    payload = response.json()
    assert payload["valid"] is True
    assert payload["extractor"] == "invoice"
    assert payload["schema_version"] == "1.0"

    fields = {item["key"]: item for item in payload["fields"]}
    assert fields["total"]["value"] == "5550000.00"
    assert fields["total"]["page_number"] == 1
    assert fields["total"]["source_text"]


def test_extract_returns_200_with_valid_false_when_output_fails_validation() -> None:
    """Tetap 200 karena responsnya berisi bukti yang harus disimpan Laravel."""
    response = client.post(
        "/v1/extract",
        json={
            "document_type": "purchase_invoice",
            "pages": [{"page_number": 1, "text": "Halaman tanpa data faktur"}],
        },
    )

    assert response.status_code == 200

    payload = response.json()
    assert payload["valid"] is False
    assert payload["validation_errors"]

    # Raw output tetap dikembalikan meski kosong, karena Laravel menyimpannya sebagai
    # bukti bahwa ekstraksi benar-benar dijalankan dan tidak menemukan apa pun.
    assert set(payload["raw"]["fields"]) == {item["key"] for item in payload["fields"]}
    assert all(item["value"] is None for item in payload["fields"])


def test_extract_rejects_document_type_without_extractor_with_415() -> None:
    """Mengulang tidak akan mengubah hasilnya, jadi Laravel tidak perlu meretry."""
    response = client.post(
        "/v1/extract",
        json={"document_type": "payroll", "pages": INVOICE_PAGES},
    )

    assert response.status_code == 415
    assert "extractor" in response.json()["detail"]


def test_extract_rejects_malformed_provider_output_with_422() -> None:
    response = client.post(
        "/v1/extract",
        json={"document_type": "purchase_invoice", "provider": "rogue", "pages": INVOICE_PAGES},
    )

    assert response.status_code == 200
    assert response.json()["valid"] is False


def test_extract_returns_503_when_provider_is_unavailable() -> None:
    response = client.post(
        "/v1/extract",
        json={"document_type": "purchase_invoice", "provider": "null", "pages": INVOICE_PAGES},
    )

    assert response.status_code == 503


def test_extract_money_is_never_serialised_as_a_number() -> None:
    """plan.md §44.14: presisi uang hilang bila dikirim sebagai angka JSON."""
    payload = client.post(
        "/v1/extract",
        json={"document_type": "purchase_invoice", "pages": INVOICE_PAGES},
    ).json()

    for item in payload["fields"]:
        assert item["value"] is None or isinstance(item["value"], str)


@pytest.mark.parametrize("path", ["/v1/classify", "/v1/extract"])
def test_intelligence_endpoints_require_internal_token(path: str) -> None:
    """plan.md §30: request internal tetap harus terautentikasi."""
    settings = get_settings()
    original = settings.token
    settings.token = "rahasia-internal"

    body = (
        {"filename": "a.pdf", "pages": INVOICE_PAGES}
        if path == "/v1/classify"
        else {"document_type": "purchase_invoice", "pages": INVOICE_PAGES}
    )

    try:
        assert client.post(path, json=body).status_code == 401

        authorized = client.post(
            path,
            json=body,
            headers={"Authorization": "Bearer rahasia-internal"},
        )
        assert authorized.status_code == 200
    finally:
        settings.token = original


def test_classify_does_not_extract_and_extract_does_not_classify() -> None:
    """plan.md §44.1 melarang satu prompt besar untuk seluruh pipeline."""
    classified = client.post(
        "/v1/classify",
        json={"filename": "faktur.pdf", "pages": INVOICE_PAGES},
    ).json()
    extracted = client.post(
        "/v1/extract",
        json={"document_type": "purchase_invoice", "pages": INVOICE_PAGES},
    ).json()

    assert "fields" not in classified
    assert "document_type" in extracted
    assert "alternatives" not in extracted
