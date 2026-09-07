"""Entrypoint Python AI Worker (plan.md §26, §28).

Kapabilitas aktif: parsing deterministik berkas (plan.md §13.1 PARSER ROUTER), klasifikasi
dokumen (§14.1), ekstraksi terstruktur per jenis dokumen (§14.2), dan klasifikasi
peristiwa ekonomi (§14.3). Entity resolution dan duplicate matching dijalankan di Laravel.
"""

from fastapi import FastAPI

from app.api.health import router as health_router
from app.api.intelligence import router as intelligence_router
from app.api.parse import router as parse_router
from app.config import WORKER_VERSION, get_settings

VERSION = WORKER_VERSION


def create_app() -> FastAPI:
    settings = get_settings()

    app = FastAPI(
        title=settings.app_name,
        version=VERSION,
        description=(
            "AI worker untuk parsing, OCR, klasifikasi, dan ekstraksi dokumen keuangan, "
            "serta klasifikasi peristiwa ekonomi. Kapabilitas aktif: parsing deterministik "
            "PDF, spreadsheet, CSV, dan gambar; klasifikasi dokumen; ekstraksi terstruktur "
            "faktur, struk, rekening koran, dan settlement QRIS; klasifikasi economic event."
        ),
    )

    app.include_router(health_router)
    app.include_router(parse_router)
    app.include_router(intelligence_router)

    return app


app = create_app()
