"""Entrypoint Python AI Worker (plan.md §26, §28).

Kapabilitas aktif saat ini: parsing deterministik berkas dokumen (plan.md §13.1 PARSER
ROUTER). Klasifikasi dokumen dan ekstraksi terstruktur adalah Phase 4 dan belum ada di
sini; provider AI masih NullProvider yang menolak inferensi secara eksplisit.
"""

from fastapi import FastAPI

from app.api.health import router as health_router
from app.api.parse import router as parse_router
from app.config import get_settings


def create_app() -> FastAPI:
    settings = get_settings()

    app = FastAPI(
        title=settings.app_name,
        version="0.2.0",
        description=(
            "AI worker untuk parsing, OCR, klasifikasi, dan ekstraksi dokumen keuangan. "
            "Kapabilitas aktif: parsing deterministik PDF, spreadsheet, CSV, dan gambar."
        ),
    )

    app.include_router(health_router)
    app.include_router(parse_router)

    return app


app = create_app()
