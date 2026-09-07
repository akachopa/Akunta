"""Parsing angka dan tanggal dokumen Indonesia.

plan.md §44.14 melarang float untuk uang, jadi seluruh nilai yang diuji di sini adalah
Decimal dan diserialisasi sebagai string.
"""

from datetime import date
from decimal import Decimal

import pytest

from app.numbers import money_to_string, normalize_amount, parse_date


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("Rp 1.500.000", Decimal("1500000")),
        ("Rp1.234.567,89", Decimal("1234567.89")),
        ("1,234,567.89", Decimal("1234567.89")),
        ("IDR 2.000.000,00", Decimal("2000000.00")),
        ("1500", Decimal("1500")),
        ("1.500", Decimal("1500")),
        ("12,50", Decimal("12.50")),
        ("-45.000", Decimal("-45000")),
        ("(45.000)", Decimal("-45000")),
    ],
)
def test_normalize_amount_reads_indonesian_and_international_formats(
    raw: str,
    expected: Decimal,
) -> None:
    assert normalize_amount(raw) == expected


def test_normalize_amount_returns_none_without_digits() -> None:
    assert normalize_amount("tidak ada angka") is None
    assert normalize_amount("") is None


def test_thousand_separator_is_not_mistaken_for_decimal() -> None:
    """Kesalahan yang paling mahal: "1.500" dibaca 1,5 alih-alih 1500."""
    assert normalize_amount("1.500") == Decimal("1500")
    assert normalize_amount("1.500,25") == Decimal("1500.25")


def test_money_is_serialised_as_decimal_string() -> None:
    assert money_to_string(Decimal("1500")) == "1500.00"
    assert money_to_string(Decimal("1234567.891")) == "1234567.89"


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("2026-09-04", date(2026, 9, 4)),
        ("04/09/2026", date(2026, 9, 4)),
        ("4-9-2026", date(2026, 9, 4)),
        ("04.09.26", date(2026, 9, 4)),
        ("4 September 2026", date(2026, 9, 4)),
        ("15 Des 2026", date(2026, 12, 15)),
        ("Tanggal: 2026-01-15 pukul 10:32", date(2026, 1, 15)),
    ],
)
def test_parse_date_reads_common_document_formats(raw: str, expected: date) -> None:
    assert parse_date(raw) == expected


def test_parse_date_prefers_day_first_but_recovers_from_month_first() -> None:
    """Dokumen Indonesia memakai dd/mm; berkas ekspor kadang mengirim mm/dd."""
    assert parse_date("09/25/2026") == date(2026, 9, 25)


def test_parse_date_returns_none_for_impossible_dates() -> None:
    assert parse_date("31/02/2026") is None
    assert parse_date("tanpa tanggal") is None
