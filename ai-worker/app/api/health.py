"""Health endpoint.

plan.md §37 Phase 0 menjadikan "AI worker reachable" sebagai acceptance criteria.
Endpoint ini sengaja tidak memerlukan autentikasi supaya dapat dipakai sebagai probe
container, tetapi juga tidak membocorkan konfigurasi apa pun selain nama provider.
"""

from fastapi import APIRouter

from app.config import get_settings
from app.extractors.router import supported_document_types
from app.parsers.router import supported_extensions
from app.providers.registry import available_providers
from app.schemas.health import HealthResponse

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    settings = get_settings()

    return HealthResponse(
        status="ok",
        service=settings.app_name,
        version="0.4.0",
        environment=settings.environment,
        default_provider=settings.default_provider,
        available_providers=available_providers(),
        # Hanya kapabilitas yang benar-benar terpasang yang dilaporkan. Laravel memakai
        # daftar ini untuk memastikan worker yang dihubungi memang mampu memproses
        # dokumen sebelum pipeline dijalankan.
        capabilities=["document_parse", "document_classify", "document_extract"],
        supported_extensions=supported_extensions(),
        extractable_document_types=supported_document_types(),
    )
