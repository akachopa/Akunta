"""Parsing angka uang dan tanggal dari teks dokumen.

plan.md §44.14 melarang float untuk uang, jadi seluruh nilai di modul ini adalah `Decimal`
dan diserialisasi sebagai string desimal. Konversi ke float tidak pernah terjadi, bahkan
sebagai langkah perantara.

Dokumen UMKM Indonesia memakai format ribuan yang bercampur: "1.234.567,89" (id-ID),
"1,234,567.89" (en-US), dan "1234567" tanpa pemisah. Pemisah desimal disimpulkan dari
posisi tanda baca terakhir, bukan dari locale, karena satu berkas bisa memuat keduanya.
"""

from __future__ import annotations

import re
from datetime import date
from decimal import Decimal, InvalidOperation

MONEY_SCALE = 2

# Angka dengan pemisah opsional, boleh didahului Rp/IDR dan boleh diapit tanda kurung
# atau minus sebagai penanda negatif.
_AMOUNT_PATTERN = re.compile(
    r"""
    (?P<negative_open>\()?
    \s*(?:rp\.?|idr)?\s*
    (?P<sign>-)?
    \s*
    # Cabang pertama menangani angka berpemisah ribuan dan wajib memuat pemisahnya,
    # supaya "1500" tidak terpotong menjadi "150" oleh cabang ini.
    (?P<digits>\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)
    \s*
    (?P<negative_close>\))?
    """,
    re.IGNORECASE | re.VERBOSE,
)

_MONTHS_ID = {
    "januari": 1,
    "februari": 2,
    "maret": 3,
    "april": 4,
    "mei": 5,
    "juni": 6,
    "juli": 7,
    "agustus": 8,
    "september": 9,
    "oktober": 10,
    "november": 11,
    "desember": 12,
    "jan": 1,
    "feb": 2,
    "mar": 3,
    "apr": 4,
    "jun": 6,
    "jul": 7,
    "agu": 8,
    "ags": 8,
    "aug": 8,
    "sep": 9,
    "okt": 10,
    "oct": 10,
    "nov": 11,
    "des": 12,
    "dec": 12,
    "january": 1,
    "february": 2,
    "march": 3,
    "may": 5,
    "june": 6,
    "july": 7,
    "august": 8,
    "october": 10,
    "december": 12,
}

_ISO_DATE = re.compile(r"\b(?P<year>\d{4})-(?P<month>\d{1,2})-(?P<day>\d{1,2})\b")
_NUMERIC_DATE = re.compile(r"\b(?P<day>\d{1,2})[/\-.](?P<month>\d{1,2})[/\-.](?P<year>\d{2,4})\b")
_TEXTUAL_DATE = re.compile(
    r"\b(?P<day>\d{1,2})\s+(?P<month>[A-Za-z]{3,9})\.?\s+(?P<year>\d{4})\b",
)


def normalize_amount(raw: str) -> Decimal | None:
    """Mengubah potongan teks menjadi Decimal, atau None bila tidak ada angka di dalamnya."""

    match = _AMOUNT_PATTERN.search(raw or "")

    if match is None:
        return None

    digits = match.group("digits")
    negative = bool(match.group("sign")) or (
        bool(match.group("negative_open")) and bool(match.group("negative_close"))
    )

    value = _to_decimal(digits)

    if value is None:
        return None

    return -value if negative else value


def _to_decimal(digits: str) -> Decimal | None:
    separators = [index for index, char in enumerate(digits) if char in ".,"]

    if not separators:
        canonical = digits
    else:
        last = separators[-1]
        tail = len(digits) - last - 1

        # Tanda baca terakhir hanya berperan sebagai pemisah desimal bila menyisakan satu
        # atau dua digit; tiga digit berarti pemisah ribuan, seperti pada "1.500". Ketika
        # ada beberapa tanda baca, yang terakhir baru pemisah desimal bila jenisnya berbeda
        # dari pemisah ribuan sebelumnya, seperti pada "1.234.567,89".
        is_decimal = tail in (1, 2) and (
            len(separators) == 1 or digits[last] != digits[separators[-2]]
        )

        if is_decimal:
            canonical = digits[:last].replace(".", "").replace(",", "") + "." + digits[last + 1 :]
        else:
            canonical = digits.replace(".", "").replace(",", "")

    try:
        return Decimal(canonical)
    except InvalidOperation:
        return None


def quantize_money(value: Decimal) -> Decimal:
    return value.quantize(Decimal(1).scaleb(-MONEY_SCALE))


def money_to_string(value: Decimal) -> str:
    return format(quantize_money(value), "f")


def parse_date(raw: str) -> date | None:
    """Mencari tanggal pertama pada teks dan mengembalikannya sebagai `date`."""

    text = raw or ""

    iso = _ISO_DATE.search(text)

    if iso is not None:
        return _safe_date(int(iso.group("year")), int(iso.group("month")), int(iso.group("day")))

    textual = _TEXTUAL_DATE.search(text)

    if textual is not None:
        month = _MONTHS_ID.get(textual.group("month").lower())

        if month is not None:
            return _safe_date(int(textual.group("year")), month, int(textual.group("day")))

    numeric = _NUMERIC_DATE.search(text)

    if numeric is not None:
        year = int(numeric.group("year"))

        # Tahun dua digit pada dokumen keuangan UMKM selalu abad ini.
        if year < 100:
            year += 2000

        # Format Indonesia adalah hari-bulan-tahun. Bila angka pertama melebihi 12 dan
        # angka kedua tidak, urutannya sudah pasti; sebaliknya diasumsikan dd/mm.
        day = int(numeric.group("day"))
        month = int(numeric.group("month"))

        if month > 12 and day <= 12:
            day, month = month, day

        return _safe_date(year, month, day)

    return None


def _safe_date(year: int, month: int, day: int) -> date | None:
    try:
        return date(year, month, day)
    except ValueError:
        return None
