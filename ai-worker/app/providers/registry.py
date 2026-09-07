"""Pemilihan provider AI berdasarkan konfigurasi (plan.md §13.2, §13.3).

Model routing plan.md §13.2 diterapkan di sini: setiap task boleh memakai provider yang
berbeda, sehingga klasifikasi sederhana dapat memakai tingkat termurah sementara ekstraksi
memakai model yang lebih mampu. Routing berupa konfigurasi, bukan percabangan di kode
domain, supaya penggantian provider tidak menyentuh logika akuntansi (plan.md §44.12).
"""

from __future__ import annotations

from app.config import get_settings
from app.providers.base import AIProviderInterface, NullProvider
from app.providers.heuristic import HeuristicProvider
from app.providers.openai import OpenAIProvider

_PROVIDERS: dict[str, type[AIProviderInterface]] = {
    NullProvider.name: NullProvider,
    HeuristicProvider.name: HeuristicProvider,
    OpenAIProvider.name: OpenAIProvider,
}


def register_provider(provider: type[AIProviderInterface]) -> None:
    _PROVIDERS[provider.name] = provider


def available_providers() -> list[str]:
    return sorted(_PROVIDERS)


def resolve_provider(name: str | None = None) -> AIProviderInterface:
    settings = get_settings()
    key = name or settings.default_provider

    if key not in _PROVIDERS:
        raise KeyError(f"Provider AI [{key}] tidak terdaftar.")

    return _PROVIDERS[key]()


def resolve_for_task(task: str, override: str | None = None) -> AIProviderInterface:
    """Provider untuk satu task pipeline (plan.md §13.2 model routing)."""

    if override is not None:
        return resolve_provider(override)

    settings = get_settings()

    routing = {
        "classify_document": settings.provider_classify,
        "extract_document": settings.provider_extract,
        "classify_economic_event": settings.provider_classify_event,
    }

    return resolve_provider(routing.get(task) or settings.default_provider)
