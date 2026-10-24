"""Tests for rule_reader.py. The memo below is a made-up sample, not a real agency memo."""
import rule_reader
from rule_reader import suggest

OPTIONS = {
    "year_levels": ["1st Year", "2nd Year", "3rd Year", "4th Year"],
    "colleges": ["College of Agriculture and Agri-Industries", "College of Engineering and Geo-Sciences", "College of Nursing"],
    "documents": ["Certificate of Registration (COR)", "Certificate of Grades", "Parents' Income Tax Return", "Valid ID"],
}
MEMO = """SAMPLE MEMO (fictional). The program is open to incoming first year and second year students taking
agriculture, forestry or fisheries courses. Applicants must have a general weighted average of 2.25 or better.
The combined annual family income must not exceed PHP 300,000. Grantees must not be a recipient of any other
scholarship. Submit the Certificate of Registration, Certificate of Grades and the parents' Income Tax Return on or
before November 20, 2026. Grantees receive PHP 20,000 per semester."""


def test_patterns_read_the_basic_rules():
    result = suggest(MEMO, OPTIONS, use_llm=False)
    s = result["suggestions"]
    assert result["method"] == "patterns"
    assert s["max_gwa"]["value"] == 2.25 and "2.25" in s["max_gwa"]["quote"]
    assert s["max_family_income"]["value"] == 300000
    assert s["year_levels"]["value"] == ["1st Year", "2nd Year"]
    assert s["colleges"]["value"] == ["College of Agriculture and Agri-Industries"]   # from the field "agriculture"
    assert s["one_scholarship_only"]["value"] is True
    assert set(s["required_documents"]["value"]) == {"Certificate of Registration (COR)", "Certificate of Grades", "Parents' Income Tax Return"}
    assert s["deadline"]["value"] == "2026-11-20"
    assert s["amount"]["value"] == 20000
    assert s["fields_of_study"]["value"] == ["Agriculture"]


def test_llm_answers_are_checked(monkeypatch):
    fake = {
        "max_gwa": {"value": 2.25, "quote": "Applicants must have a general weighted average of 2.25 or better."},
        "max_family_income": {"value": 250000, "quote": "Income must be below 250,000."},    # not in the memo
        "colleges": {"value": ["College of Agriculture and Agri-Industries", "College of Magic"], "quote": None},
        "year_levels": {"value": ["5th Year"], "quote": None},                                 # not an allowed choice
    }
    monkeypatch.setattr(rule_reader, "_ollama", lambda text, options: fake)
    result = suggest(MEMO, OPTIONS)
    s = result["suggestions"]
    assert result["method"].startswith("ollama:")
    assert s["max_gwa"]["check"] is False and s["max_gwa"]["by"] == "llm"
    assert s["max_family_income"]["value"] == 250000 and s["max_family_income"]["check"] is True   # flagged for staff
    assert s["colleges"]["value"] == ["College of Agriculture and Agri-Industries"]                 # made-up college dropped
    assert s["year_levels"]["value"] == ["1st Year", "2nd Year"] and s["year_levels"]["by"] == "pattern"


def test_without_ollama_it_falls_back(monkeypatch):
    def down(text, options):
        raise OSError("connection refused")

    monkeypatch.setattr(rule_reader, "_ollama", down)
    result = suggest(MEMO, OPTIONS)
    assert result["method"] == "patterns" and "not running" in result["warnings"][0]
