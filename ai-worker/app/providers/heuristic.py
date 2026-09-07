"""Provider berbasis aturan (plan.md §13.2 "small model / classifier").

plan.md §13.2 melarang memakai model mahal untuk semua task. Tingkat termurah pada routing
adalah aturan deterministik, dan provider ini adalah tingkat tersebut: tidak ada panggilan
jaringan, tidak ada biaya token, dan hasilnya dapat direproduksi untuk berkas yang sama.

Provider ini juga menjadi default agar seluruh pipeline dapat dijalankan dan diuji tanpa
kredensial vendor. Konsekuensinya dijaga sengaja: confidence-nya dibatasi di bawah ambang
auto-ready, sehingga dokumen yang hanya diklasifikasi oleh aturan selalu melewati manusia.
plan.md §32.2 menetapkan false auto approval harus sangat rendah, dan classifier kata kunci
tidak layak memutuskan sendiri isi buku besar.
"""

from __future__ import annotations

import re
import time
from dataclasses import dataclass
from typing import Any

from app.numbers import money_to_string, normalize_amount, parse_date
from app.providers.base import AIProviderError, AIProviderInterface, ProviderResult, ProviderUsage
from app.taxonomy import UNKNOWN_TYPE, priority_of

# Batas atas confidence provider aturan. Nilainya berada di bawah default
# akunta.confidence.auto_ready (0.95) supaya hasil aturan tidak pernah lolos otomatis.
CONFIDENCE_CEILING = 0.94

RULES_VERSION = "rules-1.0"


@dataclass(frozen=True)
class _TypeRule:
    document_type: str
    keywords: tuple[str, ...]

    # Kata kunci yang, bila muncul, meniadakan jenis ini. Dipakai untuk memisahkan dokumen
    # yang kosakatanya sangat mirip, misalnya bukti transfer versus rekening koran.
    excludes: tuple[str, ...] = ()


_TYPE_RULES: tuple[_TypeRule, ...] = (
    _TypeRule(
        "bank_statement",
        (
            "rekening koran",
            "mutasi rekening",
            "mutasi tabungan",
            "saldo awal",
            "saldo akhir",
            "account statement",
            "bank statement",
            "no. rekening",
            "nomor rekening",
        ),
    ),
    _TypeRule(
        "qris_settlement",
        (
            "qris",
            "settlement",
            "mdr",
            "merchant discount rate",
            "net settlement",
            "dana diteruskan",
        ),
    ),
    _TypeRule(
        "payroll",
        ("slip gaji", "payroll", "gaji pokok", "tunjangan", "potongan bpjs", "take home pay"),
    ),
    _TypeRule(
        "utility_bill",
        (
            "tagihan listrik",
            "token listrik",
            "pln",
            "pdam",
            "tagihan air",
            "tagihan internet",
            "indihome",
            "telkom",
            "meter",
        ),
    ),
    _TypeRule(
        "transfer_proof",
        (
            "bukti transfer",
            "transfer berhasil",
            "transaksi berhasil",
            "berhasil dikirim",
            "bukti pembayaran",
            "nomor referensi",
        ),
        excludes=("saldo awal", "saldo akhir", "mutasi rekening"),
    ),
    _TypeRule(
        "receipt",
        ("struk", "nota", "kasir", "cash receipt", "receipt", "kembalian", "tunai"),
        excludes=("invoice", "faktur"),
    ),
    _TypeRule(
        "marketplace_report",
        (
            "shopee",
            "tokopedia",
            "tiktok shop",
            "lazada",
            "blibli",
            "marketplace",
            "pencairan dana",
            "biaya administrasi",
        ),
    ),
    _TypeRule(
        "pos_report",
        (
            "laporan penjualan",
            "laporan kasir",
            "rekap penjualan",
            "tutup shift",
            "shift kasir",
            "sales report",
            "pos report",
        ),
        excludes=("faktur", "invoice"),
    ),
    _TypeRule(
        "purchase_invoice",
        ("invoice", "faktur", "tagihan", "jatuh tempo", "due date", "purchase order", "po no"),
    ),
    _TypeRule(
        "sales_invoice",
        ("invoice", "faktur penjualan", "tagihan kepada", "sales invoice", "jatuh tempo"),
    ),
)

# Label yang dipakai mencari nilai pada dokumen.
#
# Urutan di dalam setiap tuple penting: label dicari dari yang paling spesifik ke yang
# paling umum, dan yang lebih spesifik menang di seluruh dokumen sebelum yang umum dicoba.
# Tanpa urutan itu "Tanggal Jatuh Tempo" akan terbaca sebagai tanggal dokumen hanya karena
# barisnya muncul lebih dulu.
_LABELS: dict[str, tuple[str, ...]] = {
    "document_number": (
        "no. invoice",
        "no invoice",
        "nomor invoice",
        "invoice number",
        "invoice no",
        "no. faktur",
        "nomor faktur",
        "no. nota",
        "nomor nota",
        "no. struk",
        "no. transaksi",
        "nomor referensi",
        "reference no",
    ),
    "document_date": (
        "tanggal invoice",
        "tanggal faktur",
        "tanggal nota",
        "tanggal laporan",
        "tanggal transaksi",
        "invoice date",
        "report date",
        "tanggal",
        "tgl",
        "date",
    ),
    "due_date": ("tanggal jatuh tempo", "jatuh tempo", "due date"),
    "issuer_name": (
        "diterbitkan oleh",
        "nama penerbit",
        "nama penjual",
        "nama pemasok",
        "penerbit",
        "penjual",
        "pemasok",
        "supplier",
        "vendor",
        "seller",
        "dari",
        "from",
    ),
    "buyer_name": (
        "nama pembeli",
        "pembeli",
        "bill to",
        "customer",
        "pelanggan",
        "kepada",
    ),
    "merchant_name": ("nama merchant", "merchant name", "nama toko", "outlet", "gerai", "merchant"),
    "merchant_id": ("merchant id", "id merchant", "mid"),
    "subtotal": ("jumlah sebelum pajak", "sub total", "subtotal", "dpp"),
    "tax": ("ppn", "pajak", "vat", "tax"),
    "total": ("grand total", "total bayar", "total tagihan", "jumlah total", "total"),
    "payment_method": ("metode pembayaran", "payment method", "dibayar dengan", "pembayaran"),
    "currency": ("mata uang", "currency"),
    "account_number": ("no. rekening", "nomor rekening", "no rekening", "account number"),
    "account_holder": ("nama pemilik", "nama rekening", "atas nama", "account holder"),
    "bank_name": ("nama bank", "bank"),
    "period_start": ("periode awal", "dari tanggal", "period start"),
    "period_end": ("periode akhir", "sampai tanggal", "period end"),
    "opening_balance": ("saldo awal", "saldo pembukaan", "opening balance"),
    "closing_balance": ("saldo akhir", "saldo penutup", "closing balance"),
    "settlement_date": ("tanggal settlement", "tanggal penyelesaian", "settlement date"),
    "gross_amount": ("total penjualan", "nilai transaksi", "gross amount", "omzet", "gross"),
    "mdr_fee": ("biaya mdr", "merchant discount", "biaya layanan", "mdr"),
    "net_amount": ("net settlement", "dana diteruskan", "jumlah diterima", "net amount", "netto"),
    "transaction_count": ("jumlah transaksi", "transaction count"),
}

# Field yang nilainya berupa uang, tanggal, atau bilangan bulat. Sisanya diperlakukan
# sebagai teks.
_MONEY_FIELDS = frozenset(
    {
        "subtotal",
        "tax",
        "total",
        "opening_balance",
        "closing_balance",
        "gross_amount",
        "mdr_fee",
        "net_amount",
    }
)
_DATE_FIELDS = frozenset(
    {"document_date", "due_date", "period_start", "period_end", "settlement_date"}
)
_INTEGER_FIELDS = frozenset({"transaction_count"})


@dataclass
class _Found:
    value: str
    confidence: float
    page_number: int | None
    source_text: str | None


class HeuristicProvider(AIProviderInterface):
    name = "heuristic"

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        started = time.perf_counter()

        haystack = " \n ".join(
            str(part or "").lower()
            for part in (payload.get("filename"), payload.get("sample_text"))
        )

        scores = self._score_types(haystack)
        ranked = self._rank(scores)

        if not ranked:
            best_type, best_confidence = UNKNOWN_TYPE, 0.0
            reason = "Tidak ada kata kunci jenis dokumen yang dikenali pada teks berkas."
        else:
            best_type, hits = ranked[0]
            best_type = self._disambiguate_invoice(best_type, haystack, payload)
            best_confidence = self._confidence(hits)
            reason = f"Cocok dengan {hits} penanda kata kunci untuk jenis dokumen ini."

        alternatives = [
            {"document_type": document_type, "confidence": self._confidence(hits)}
            for document_type, hits in ranked[1:4]
        ]

        return ProviderResult(
            data={
                "document_type": best_type,
                "confidence": best_confidence,
                "reason": reason,
                "alternatives": alternatives,
            },
            provider=self.name,
            model=self.name,
            prompt_version=RULES_VERSION,
            latency_ms=int((time.perf_counter() - started) * 1000),
            usage=ProviderUsage(),
        )

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        started = time.perf_counter()

        pages = payload.get("pages") or []
        wanted: list[str] = list(payload.get("fields") or [])

        fields: dict[str, Any] = {}

        for key in wanted:
            found = self._find(key, pages)

            fields[key] = (
                None
                if found is None
                else {
                    "value": found.value,
                    "confidence": found.confidence,
                    "page_number": found.page_number,
                    "source_text": found.source_text,
                }
            )

        rows = (
            self._extract_rows(pages, list(payload.get("row_fields") or []))
            if payload.get("with_rows")
            else []
        )

        return ProviderResult(
            data={"fields": fields, "rows": rows},
            provider=self.name,
            model=self.name,
            prompt_version=RULES_VERSION,
            latency_ms=int((time.perf_counter() - started) * 1000),
            usage=ProviderUsage(),
        )

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        raise AIProviderError("Economic event classifier adalah Phase 8.")

    def _score_types(self, haystack: str) -> dict[str, int]:
        scores: dict[str, int] = {}

        for rule in _TYPE_RULES:
            if any(exclude in haystack for exclude in rule.excludes):
                continue

            hits = sum(1 for keyword in rule.keywords if keyword in haystack)

            if hits > 0:
                scores[rule.document_type] = max(scores.get(rule.document_type, 0), hits)

        return scores

    def _rank(self, scores: dict[str, int]) -> list[tuple[str, int]]:
        # Seri diputus oleh prioritas produk plan.md §37, bukan urutan alfabet.
        return sorted(scores.items(), key=lambda item: (-item[1], priority_of(item[0])))

    def _confidence(self, hits: int) -> float:
        return round(min(CONFIDENCE_CEILING, 0.55 + 0.09 * hits), 4)

    def _disambiguate_invoice(
        self,
        document_type: str,
        haystack: str,
        payload: dict[str, Any],
    ) -> str:
        """Memisahkan faktur penjualan dari faktur pembelian lewat konteks bisnis.

        plan.md §14.1 menyertakan `business_context` justru untuk kasus ini: dokumen yang
        sama secara kosakata berbeda arti tergantung siapa penerbitnya. Bila nama bisnis
        muncul sebagai penerbit, dokumennya adalah penjualan; bila sebagai penerima,
        dokumennya adalah pembelian.
        """

        if document_type not in ("purchase_invoice", "sales_invoice"):
            return document_type

        business = str(payload.get("business_context") or "").strip().lower()

        if business == "":
            return document_type

        issuer_zone, _, buyer_zone = haystack.partition("kepada")

        if business in issuer_zone and business not in buyer_zone:
            return "sales_invoice"

        if business in buyer_zone:
            return "purchase_invoice"

        return document_type

    def _find(self, key: str, pages: list[dict[str, Any]]) -> _Found | None:
        """Mencari satu field di seluruh halaman.

        Label menjadi loop terluar, bukan halaman: label yang lebih spesifik harus menang
        di seluruh dokumen sebelum label yang lebih umum dicoba sama sekali.
        """

        for label in _LABELS.get(key, ()):
            for page in pages:
                page_number = int(page.get("page_number") or 1)

                hit = self._find_in_text(key, label, str(page.get("text") or ""), page_number)

                if hit is not None:
                    return hit

                hit = self._find_in_rows(key, label, page.get("rows") or [], page_number)

                if hit is not None:
                    return hit

        return None

    def _find_in_text(
        self,
        key: str,
        label: str,
        text: str,
        page_number: int,
    ) -> _Found | None:
        if text == "":
            return None

        lines = text.splitlines()

        for index, line in enumerate(lines):
            position = self._label_position(line.lower(), label)

            if position is None:
                continue

            remainder = line[position + len(label) :]

            if self._label_is_incomplete(remainder):
                continue

            stripped = remainder.lstrip(" :\t|-")
            value = self._coerce(key, stripped)

            if value is not None:
                return _Found(value, 0.88, page_number, self._snippet(line))

            # Label pada dokumen sering berdiri sendiri di satu baris, dengan nilainya di
            # baris berikutnya. Kolom tabel yang dipecah antarbaris juga berbentuk begitu.
            if stripped == "" and index + 1 < len(lines):
                value = self._coerce(key, lines[index + 1])

                if value is not None:
                    return _Found(value, 0.82, page_number, self._snippet(lines[index + 1]))

        return None

    def _label_position(self, lowered: str, label: str) -> int | None:
        """Posisi label bila ia berdiri sebagai kata utuh.

        Tanpa pemeriksaan batas kata, label "total" akan ditemukan di dalam "subtotal" dan
        nilai subtotal terbaca sebagai total dokumen.
        """

        start = 0

        while True:
            position = lowered.find(label, start)

            if position < 0:
                return None

            before = lowered[position - 1] if position > 0 else " "
            after_index = position + len(label)
            after = lowered[after_index] if after_index < len(lowered) else " "

            if not before.isalnum() and not after.isalnum():
                return position

            start = position + 1

    def _label_is_incomplete(self, remainder: str) -> bool:
        """True bila label yang cocok hanyalah awalan dari nama field yang sebenarnya.

        Pada "Merchant ID: MID-889" label "merchant" cocok, tetapi nama field-nya adalah
        "Merchant ID". Sisa baris yang masih memuat kata sebelum pemisah menandakan hal itu,
        sehingga nilainya tidak diambil untuk field yang salah.
        """

        return re.match(r"\s*[A-Za-z][A-Za-z ]{0,20}[:|]", remainder) is not None

    def _find_in_rows(
        self,
        key: str,
        label: str,
        rows: list[Any],
        page_number: int,
    ) -> _Found | None:
        for row in rows:
            if not isinstance(row, list):
                continue

            cells = [str(cell) if cell is not None else "" for cell in row]

            for index, cell in enumerate(cells):
                # Pada tabel, sel label berisi nama field itu saja. Pencocokan tepat
                # menghindari sel "Merchant ID" terbaca sebagai "Merchant".
                if cell.lower().strip().strip(":") != label:
                    continue

                for candidate in cells[index + 1 :]:
                    value = self._coerce(key, candidate)

                    if value is not None:
                        return _Found(value, 0.88, page_number, self._snippet(" | ".join(cells)))

        return None

    def _coerce(self, key: str, raw: str) -> str | None:
        candidate = (raw or "").strip()

        if candidate == "":
            return None

        if key in _MONEY_FIELDS:
            amount = normalize_amount(candidate)

            return None if amount is None else money_to_string(amount)

        if key in _DATE_FIELDS:
            parsed = parse_date(candidate)

            return None if parsed is None else parsed.isoformat()

        if key in _INTEGER_FIELDS:
            amount = normalize_amount(candidate)

            return None if amount is None else str(int(amount))

        # Nilai teks dipotong pada pemisah kolom agar tidak menelan sisa baris.
        for separator in ("|", "  "):
            if separator in candidate:
                candidate = candidate.split(separator)[0].strip()

        return candidate or None

    def _snippet(self, line: str, limit: int = 200) -> str:
        collapsed = " ".join(line.split())

        return collapsed if len(collapsed) <= limit else collapsed[: limit - 1] + "…"

    def _extract_rows(
        self,
        pages: list[dict[str, Any]],
        row_keys: list[str],
    ) -> list[dict[str, Any]]:
        """Mengubah tabel baris menjadi baris bertipe.

        Dipakai oleh extractor rekening koran maupun daftar transaksi spreadsheet. Kolom
        dikenali dari baris header, bukan dari posisinya, karena setiap bank dan setiap
        aplikasi kasir menyusun kolomnya berbeda.

        Kolom yang dicari ditentukan extractor-nya: mutasi rekening memakai pasangan debit
        dan kredit, sedangkan laporan penjualan memakai satu kolom nominal bertanda.
        """

        keys = row_keys or ["date", "description", "debit", "credit", "balance"]
        collected: list[dict[str, Any]] = []

        for page in pages:
            rows = page.get("rows") or []
            page_number = int(page.get("page_number") or 1)
            mapping: dict[str, int] | None = None

            for row in rows:
                if not isinstance(row, list):
                    continue

                cells = [str(cell).strip() if cell is not None else "" for cell in row]

                if mapping is None:
                    # Tabel jarang dimulai di baris pertama: di atasnya biasanya ada baris
                    # identitas rekening atau judul laporan. Baris dianggap header hanya
                    # bila memuat kolom tanggal dan setidaknya satu kolom nilai, sehingga
                    # pencarian berlanjut sampai tabel yang sebenarnya ditemukan.
                    candidate = self._map_columns(cells, keys)

                    if self._is_row_header(candidate):
                        mapping = candidate

                    continue

                parsed = self._parse_table_row(cells, mapping, keys, page_number)

                if parsed is not None:
                    collected.append(parsed)

        return collected

    def _map_columns(self, header: list[str], keys: list[str]) -> dict[str, int]:
        aliases: dict[str, tuple[str, ...]] = {
            "date": ("tanggal", "tgl", "date", "waktu"),
            "description": ("keterangan", "uraian", "description", "berita", "transaksi"),
            "debit": ("debit", "debet", "keluar", "withdrawal"),
            "credit": ("kredit", "credit", "masuk", "deposit", "setoran"),
            "balance": ("saldo", "balance"),
            "amount": ("nominal", "jumlah", "amount", "nilai", "total", "penjualan", "omzet"),
        }

        mapping: dict[str, int] = {}

        for index, cell in enumerate(header):
            lowered = cell.lower()

            for field in keys:
                names = aliases.get(field, ())

                if field not in mapping and any(name in lowered for name in names):
                    mapping[field] = index

        return mapping

    def _is_row_header(self, mapping: dict[str, int]) -> bool:
        return "date" in mapping and bool({"debit", "credit", "balance", "amount"} & set(mapping))

    def _parse_table_row(
        self,
        cells: list[str],
        mapping: dict[str, int],
        keys: list[str],
        page_number: int,
    ) -> dict[str, Any] | None:
        if not mapping or "date" not in mapping:
            return None

        raw_date = cells[mapping["date"]] if mapping["date"] < len(cells) else ""
        parsed_date = parse_date(raw_date)

        if parsed_date is None:
            return None

        parsed: dict[str, Any] = {
            "page_number": page_number,
            "source_text": self._snippet(" | ".join(cells)),
        }

        for key in keys:
            if key == "date":
                parsed[key] = parsed_date.isoformat()
            elif key == "description":
                parsed[key] = self._cell(cells, mapping.get(key)) or ""
            else:
                parsed[key] = self._money_cell(cells, mapping.get(key))

        return parsed

    def _cell(self, cells: list[str], index: int | None) -> str | None:
        if index is None or index >= len(cells):
            return None

        return cells[index].strip() or None

    def _money_cell(self, cells: list[str], index: int | None) -> str | None:
        raw = self._cell(cells, index)

        if raw is None:
            return None

        amount = normalize_amount(raw)

        return None if amount is None else money_to_string(amount)
