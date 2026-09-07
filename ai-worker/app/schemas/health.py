"""Schema response health check."""

from pydantic import BaseModel, Field


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    environment: str
    default_provider: str
    available_providers: list[str]

    # Daftar kapabilitas pipeline yang sudah aktif (plan.md §13.1). Berisi
    # "document_parse" sejak Phase 3; classifier dan extractor menyusul pada Phase 4.
    capabilities: list[str] = Field(default_factory=list)

    # Ekstensi berkas yang punya parser. Laravel memvalidasi upload dengan daftarnya
    # sendiri, tetapi menampilkan daftar ini membuat ketidakcocokan konfigurasi terlihat.
    supported_extensions: list[str] = Field(default_factory=list)
