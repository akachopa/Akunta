from fastapi.testclient import TestClient

from app.main import create_app

client = TestClient(create_app())


def test_health_endpoint_reports_ok() -> None:
    response = client.get("/health")

    assert response.status_code == 200

    payload = response.json()
    assert payload["status"] == "ok"
    assert payload["version"] == "0.1.0"
    assert "null" in payload["available_providers"]


def test_health_reports_no_pipeline_capabilities_yet() -> None:
    """Phase 0 belum memiliki parser maupun classifier (plan.md §37 Phase 3, Phase 4)."""
    response = client.get("/health")

    assert response.json()["capabilities"] == []
