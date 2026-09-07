"""Klasifikasi peristiwa ekonomi (plan.md §14.3, §37 Phase 8)."""

from typing import Any

import pytest

from app.classifiers.economic_event import classify_event
from app.providers.base import (
    AIProviderInterface,
    AIProviderOutputError,
    ProviderResult,
    ProviderUsage,
)
from app.providers.heuristic import HeuristicProvider
from app.taxonomy import ECONOMIC_EVENT_CODES


class _StubProvider(AIProviderInterface):
    name = "stub"

    def __init__(self, data: dict[str, Any]) -> None:
        self.data = data

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        raise NotImplementedError

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        raise NotImplementedError

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        return ProviderResult(
            data=self.data,
            provider=self.name,
            model="stub-1",
            prompt_version="1.0",
            latency_ms=1,
            usage=ProviderUsage(),
        )


def test_heuristic_recognises_cash_deposit() -> None:
    outcome = classify_event(
        description="SETORAN TUNAI",
        amount="2000000.00",
        direction="inflow",
        document_type="bank_statement",
        provider=HeuristicProvider(),
    )

    assert outcome.event_code == "CASH_TO_BANK_TRANSFER"
    assert outcome.confidence >= 0.9


def test_code_outside_taxonomy_is_rejected() -> None:
    """AI tidak boleh mengarang kode di luar plan.md §10."""
    provider = _StubProvider({"event_code": "REVENUE_MAGIC", "confidence": 0.99})

    with pytest.raises(AIProviderOutputError, match="taksonomi"):
        classify_event(
            description="apa saja",
            amount="1",
            direction="inflow",
            provider=provider,
        )


def test_taxonomy_covers_required_events() -> None:
    assert "BANK_FEE" in ECONOMIC_EVENT_CODES
    assert "OWNER_CAPITAL" in ECONOMIC_EVENT_CODES
    assert "LOAN_PRINCIPAL_PAYMENT" in ECONOMIC_EVENT_CODES
    assert "BANK_TRANSFER_INTERNAL" in ECONOMIC_EVENT_CODES
