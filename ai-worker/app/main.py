"""Entrypoint Python AI Worker (plan.md §26, §28).

Pada Phase 0 worker hanya menyediakan health endpoint dan kerangka provider. Endpoint
parsing, klasifikasi, dan ekstraksi ditambahkan pada Phase 3–4 sesuai plan.md §37.
"""

from fastapi import FastAPI

from app.api.health import router as health_router
from app.config import get_settings


def create_app() -> FastAPI:
    settings = get_settings()

    app = FastAPI(
        title=settings.app_name,
        version="0.1.0",
        description=(
            "AI worker untuk parsing, OCR, klasifikasi, dan ekstraksi dokumen keuangan. "
            "Phase 0 hanya menyediakan health check."
        ),
    )

    app.include_router(health_router)

    return app


app = create_app()
