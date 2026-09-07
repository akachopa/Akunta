"""Pemilihan provider AI berdasarkan konfigurasi (plan.md §13.2, §13.3)."""

from __future__ import annotations

from app.config import get_settings
from app.providers.base import AIProviderInterface, NullProvider

_PROVIDERS: dict[str, type[AIProviderInterface]] = {
    NullProvider.name: NullProvider,
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
