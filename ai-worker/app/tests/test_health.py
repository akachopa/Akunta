from fastapi.testclient import TestClient

from app.main import create_app

client = TestClient(create_app())


def test_health_endpoint_reports_ok() -> None:
    response = client.get("/health")

    assert response.status_code == 200

    payload = response.json()
    assert payload["status"] == "ok"
    assert payload["version"] == "0.2.0"
    assert "null" in payload["available_providers"]


def test_health_reports_parse_capability() -> None:
    """Phase 3 memasang parser deterministik (plan.md §13.1)."""
    payload = client.get("/health").json()

    assert payload["capabilities"] == ["document_parse"]
    assert set(payload["supported_extensions"]) >= {"csv", "jpg", "pdf", "png", "xlsx"}


def test_health_does_not_claim_classifier_capability() -> None:
    """Classifier dan extractor adalah Phase 4 (plan.md §37 Phase 4)."""
    capabilities = client.get("/health").json()["capabilities"]

    assert "document_classify" not in capabilities
    assert "document_extract" not in capabilities
