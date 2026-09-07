from fastapi.testclient import TestClient

from app.main import create_app

client = TestClient(create_app())


def test_health_endpoint_reports_ok() -> None:
    response = client.get("/health")

    assert response.status_code == 200

    payload = response.json()
    assert payload["status"] == "ok"
    assert payload["version"] == "0.4.0"
    assert "null" in payload["available_providers"]


def test_health_reports_parse_capability() -> None:
    """Phase 3 memasang parser deterministik (plan.md §13.1)."""
    payload = client.get("/health").json()

    assert "document_parse" in payload["capabilities"]
    assert set(payload["supported_extensions"]) >= {"csv", "jpg", "pdf", "png", "xlsx"}


def test_health_reports_intelligence_capability() -> None:
    """Phase 4 menambahkan classifier dan extractor (plan.md §37 Phase 4)."""
    payload = client.get("/health").json()

    assert "document_classify" in payload["capabilities"]
    assert "document_extract" in payload["capabilities"]


def test_health_lists_providers_from_more_than_one_vendor() -> None:
    """plan.md §44.12 melarang aplikasi terikat ke satu vendor AI."""
    providers = client.get("/health").json()["available_providers"]

    assert {"heuristic", "openai"} <= set(providers)


def test_health_lists_document_types_that_have_extractor() -> None:
    """Jenis dokumen prioritas plan.md §37 Phase 4 yang sudah punya extractor."""
    extractable = set(client.get("/health").json()["extractable_document_types"])

    assert {
        "bank_statement",
        "purchase_invoice",
        "sales_invoice",
        "receipt",
        "qris_settlement",
    } <= extractable


def test_health_does_not_claim_normalization_capability() -> None:
    """Transaction normalization adalah Phase 5 (plan.md §37 Phase 5)."""
    capabilities = client.get("/health").json()["capabilities"]

    assert "transaction_normalize" not in capabilities
    assert "economic_event_classify" not in capabilities
