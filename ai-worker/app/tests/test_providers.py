from decimal import Decimal
from typing import Any

import pytest

from app.config import get_settings
from app.prompts import PromptContract
from app.providers.base import (
    AIProviderError,
    AIProviderOutputError,
    NullProvider,
    ProviderUsage,
    StructuredProvider,
)
from app.providers.heuristic import HeuristicProvider
from app.providers.registry import available_providers, resolve_for_task, resolve_provider


def test_default_provider_is_deterministic_rules() -> None:
    """Default-nya provider aturan supaya worker berguna tanpa kredensial vendor."""
    provider = resolve_provider()

    assert isinstance(provider, HeuristicProvider)
    assert {"heuristic", "null", "openai"} <= set(available_providers())


def test_unknown_provider_raises() -> None:
    with pytest.raises(KeyError):
        resolve_provider("provider-yang-tidak-ada")


@pytest.mark.parametrize(
    "task",
    ["classify_document", "extract_document", "classify_economic_event"],
)
def test_task_routing_falls_back_to_default_provider(task: str) -> None:
    """plan.md §13.2: routing per task, dengan default bila task tidak dipetakan."""
    assert isinstance(resolve_for_task(task), HeuristicProvider)


def test_task_routing_honours_explicit_override() -> None:
    assert isinstance(resolve_for_task("classify_document", "null"), NullProvider)


def test_task_routing_reads_configuration(monkeypatch: pytest.MonkeyPatch) -> None:
    """Provider per task berasal dari konfigurasi, bukan percabangan di kode domain."""
    get_settings.cache_clear()
    monkeypatch.setenv("AI_WORKER_PROVIDER_CLASSIFY", "null")

    try:
        assert isinstance(resolve_for_task("classify_document"), NullProvider)
        assert isinstance(resolve_for_task("extract_document"), HeuristicProvider)
    finally:
        get_settings.cache_clear()


@pytest.mark.parametrize(
    "method",
    ["classify_document", "extract_document", "classify_economic_event"],
)
def test_null_provider_refuses_inference(method: str) -> None:
    """Menolak dengan jelas lebih aman daripada mengembalikan prediksi palsu."""
    provider = NullProvider()

    with pytest.raises(AIProviderError):
        if method == "extract_document":
            provider.extract_document({}, {}, "1.0", "receipt")
        else:
            getattr(provider, method)({})


def test_heuristic_classifies_bank_fee() -> None:
    result = HeuristicProvider().classify_economic_event(
        {"description": "BIAYA ADMIN", "direction": "outflow", "amount": "500000.00"}
    )

    assert result.data["event_code"] == "BANK_FEE"
    assert result.data["confidence"] >= 0.9


class _FakeProvider(StructuredProvider):
    """Provider palsu untuk menguji kontrak StructuredProvider itu sendiri."""

    name = "fake"

    def __init__(self, data: Any) -> None:
        self.data = data
        self.seen: PromptContract | None = None

    def complete(self, contract: PromptContract) -> tuple[Any, str, ProviderUsage]:
        self.seen = contract

        return self.data, "fake-model-1", ProviderUsage(10, 20, Decimal("0.000123"))


def test_structured_provider_passes_prompt_contract_and_records_version() -> None:
    """plan.md §45.9: versi model dan prompt tersimpan bersama hasilnya."""
    provider = _FakeProvider({"document_type": "receipt", "confidence": 0.9})

    result = provider.classify_document({"filename": "struk.pdf"})

    assert provider.seen is not None
    assert provider.seen.name == "classify_document"
    assert provider.seen.json_schema["properties"]["document_type"]["enum"]
    assert result.model == "fake-model-1"
    assert result.prompt_version == provider.seen.version
    assert result.provider == "fake"


def test_structured_provider_records_usage_as_decimal_string() -> None:
    """plan.md §32.1: biaya per dokumen tercatat; §44.14: uang bukan float."""
    result = _FakeProvider({"ok": True}).classify_document({})

    assert result.usage.to_dict() == {
        "input_tokens": 10,
        "output_tokens": 20,
        "cost": "0.000123",
        "currency": "USD",
    }


def test_structured_provider_rejects_non_object_output() -> None:
    """Output yang bukan objek JSON tidak dapat dipetakan ke schema apa pun."""
    with pytest.raises(AIProviderOutputError):
        _FakeProvider(["bukan", "objek"]).classify_document({})
