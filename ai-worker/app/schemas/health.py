"""Schema response health check."""

from pydantic import BaseModel, Field


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    environment: str
    default_provider: str
    available_providers: list[str]

    # Daftar kapabilitas pipeline yang sudah aktif. Kosong pada Phase 0 dan diisi ketika
    # parser/classifier/extractor dibangun pada Phase 3–4 (plan.md §13.1).
    capabilities: list[str] = Field(default_factory=list)
