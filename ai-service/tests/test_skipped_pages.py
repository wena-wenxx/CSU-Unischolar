"""A scanned list whose pages have no table lines must never lose rows silently."""
import list_reader
import ruled_table


def _fake(pages, skipped):
    rows = [["Name", "Student ID"]] + [[f"PERSON {i}", f"2026-{i:05d}"] for i in range(9)]
    return lambda path: {"rows": rows, "cells": [[{}] * 2 for _ in rows], "engine": "tesseract",
                         "pages": pages, "total_pages": pages + len(skipped), "skipped_pages": skipped,
                         "rows_per_page": {1: 9}}


def test_pages_without_table_lines_are_reported(monkeypatch):
    monkeypatch.setattr(ruled_table, "ocr_engines_available", lambda: True)
    monkeypatch.setattr(ruled_table, "read_generic", _fake(3, [4, 5]))
    result = list_reader._generic_grid("list.pdf")
    assert result is not None
    assert any("Pages 4, 5 of 5" in w and "NOT read" in w for w in result["warnings"])
    assert result["metadata"]["skipped_pages"] == [4, 5]


def test_mostly_unlined_file_falls_back_to_reading_words_by_position(monkeypatch):
    monkeypatch.setattr(ruled_table, "ocr_engines_available", lambda: True)
    monkeypatch.setattr(ruled_table, "read_generic", _fake(1, [2, 3, 4, 5, 6, 7]))
    assert list_reader._generic_grid("list.pdf") is None
