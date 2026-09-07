"""Parser CSV (plan.md §13.1 CSV Parser).

Baris tabular dipertahankan apa adanya. Penafsiran kolom mana yang berupa tanggal,
deskripsi, atau nominal adalah pekerjaan Transaction Normalization pada Phase 5, bukan
pekerjaan parser.
"""

from __future__ import annotations

import csv
import io

from app.parsers.base import DocumentParseError, DocumentParser, ParsedPage, ParseResult

MAX_ROWS = 20000

# Ekspor mutasi bank dari bank Indonesia memakai koma maupun titik koma, dan sebagian
# menambahkan BOM. Semua varian itu harus terbaca tanpa konfigurasi dari user.
CANDIDATE_DELIMITERS = ",;\t|"

ENCODINGS = ("utf-8-sig", "utf-8", "cp1252", "latin-1")


def decode(content: bytes) -> tuple[str, str]:
    for encoding in ENCODINGS:
        try:
            return content.decode(encoding), encoding
        except UnicodeDecodeError:
            continue

    raise DocumentParseError("Encoding berkas teks tidak dikenali.")


def sniff_delimiter(sample: str) -> str:
    try:
        return csv.Sniffer().sniff(sample, delimiters=CANDIDATE_DELIMITERS).delimiter
    except csv.Error:
        # Sniffer gagal pada berkas satu kolom. Delimiter dengan kemunculan terbanyak
        # adalah tebakan paling aman, dan koma menjadi default terakhir.
        counts = {candidate: sample.count(candidate) for candidate in CANDIDATE_DELIMITERS}
        best = max(counts, key=lambda candidate: counts[candidate])

        return best if counts[best] > 0 else ","


class CsvParser(DocumentParser):
    name = "csv"
    version = "1.0"
    content_kind = "table"
    extensions = ("csv", "txt", "tsv")
    mime_types = ("text/csv", "text/plain", "text/tab-separated-values")

    def parse(self, content: bytes, filename: str) -> ParseResult:
        text, encoding = decode(content)

        if text.strip() == "":
            raise DocumentParseError("Berkas CSV kosong.")

        delimiter = sniff_delimiter(text[:8192])

        rows: list[list[str | None]] = []
        truncated = False

        for index, row in enumerate(csv.reader(io.StringIO(text), delimiter=delimiter)):
            if index >= MAX_ROWS:
                truncated = True
                break

            rows.append([cell.strip() or None for cell in row])

        page = ParsedPage(
            page_number=1,
            kind="table",
            label=filename,
            rows=rows,
            metadata={
                "row_count": len(rows),
                "column_count": max((len(row) for row in rows), default=0),
                "delimiter": delimiter,
                "encoding": encoding,
                "truncated": truncated,
            },
        )

        return self.result([page], metadata={"row_count": len(rows), "truncated": truncated})
