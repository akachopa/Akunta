"""Schema response health check."""

from pydantic import BaseModel, Field


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    environment: str
    default_provider: str
    available_providers: list[str]

    # Daftar kapabilitas pipeline yang sudah aktif (plan.md §13.1). Sejak Phase 4 berisi
    # parsing, klasifikasi, dan ekstraksi; normalisasi transaksi menyusul pada Phase 5.
    capabilities: list[str] = Field(default_factory=list)

    # Ekstensi berkas yang punya parser. Laravel memvalidasi upload dengan daftarnya
    # sendiri, tetapi menampilkan daftar ini membuat ketidakcocokan konfigurasi terlihat.
    supported_extensions: list[str] = Field(default_factory=list)

    # Jenis dokumen yang punya extractor. Jenis di luar daftar ini berhenti setelah
    # klasifikasi dan menunggu manusia, bukan diekstraksi dengan schema yang salah.
    extractable_document_types: list[str] = Field(default_factory=list)
