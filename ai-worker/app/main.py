"""Entrypoint Python AI Worker (plan.md §26, §28).

Kapabilitas aktif: parsing deterministik berkas (plan.md §13.1 PARSER ROUTER), klasifikasi
dokumen (§14.1), dan ekstraksi terstruktur per jenis dokumen (§14.2). Tahap setelahnya —
entity resolution, duplicate detection, dan economic event classification — belum ada di
sini dan provider menolaknya secara eksplisit.
"""

from fastapi import FastAPI

from app.api.health import router as health_router
from app.api.intelligence import router as intelligence_router
from app.api.parse import router as parse_router
from app.config import get_settings

VERSION = "0.3.0"


def create_app() -> FastAPI:
    settings = get_settings()

    app = FastAPI(
        title=settings.app_name,
        version=VERSION,
        description=(
            "AI worker untuk parsing, OCR, klasifikasi, dan ekstraksi dokumen keuangan. "
            "Kapabilitas aktif: parsing deterministik PDF, spreadsheet, CSV, dan gambar; "
            "klasifikasi dokumen; ekstraksi terstruktur faktur, struk, rekening koran, "
            "dan settlement QRIS."
        ),
    )

    app.include_router(health_router)
    app.include_router(parse_router)
    app.include_router(intelligence_router)

    return app


app = create_app()
