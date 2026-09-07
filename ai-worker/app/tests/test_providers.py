import pytest

from app.providers.base import AIProviderError, NullProvider
from app.providers.registry import available_providers, resolve_provider


def test_default_provider_is_resolvable() -> None:
    provider = resolve_provider()

    assert isinstance(provider, NullProvider)
    assert "null" in available_providers()


def test_unknown_provider_raises() -> None:
    with pytest.raises(KeyError):
        resolve_provider("provider-yang-tidak-ada")


@pytest.mark.parametrize(
    "method",
    ["classify_document", "extract_document", "classify_economic_event"],
)
def test_null_provider_refuses_inference(method: str) -> None:
    """plan.md §38: AI pipeline tidak boleh dimulai sebelum ledger reliable.

    Provider default menolak inferensi, sehingga tidak ada prediksi palsu yang bisa
    masuk ke pipeline akuntansi sebelum Phase 4.
    """
    provider = resolve_provider()

    with pytest.raises(AIProviderError):
        getattr(provider, method)({})
