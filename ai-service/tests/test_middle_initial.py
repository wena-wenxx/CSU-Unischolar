"""A middle initial in the record matches the full middle name on a document (fictional names)."""
from validation import best_name_match, validate_text


def test_initial_matches_the_full_middle_name():
    score, _ = best_name_match("Ana B. Reyes", "NAME (First) ANA (Middle) BAUTISTA (Last) REYES")
    assert score == 100


def test_a_different_middle_name_still_lowers_the_score():
    score, _ = best_name_match("Ana B. Reyes", "ANA CRUZ REYES")
    assert score < 95


def test_a_sibling_with_the_same_initials_is_not_a_match():
    result = validate_text("CERTIFICATE OF LIVE BIRTH NAME ANNE BAUTISTA REYES MOTHER MARIA CRUZ",
                           expected_name="Ana B. Reyes", document_label="PSA Birth Certificate")
    assert result["confidence_score"] < 95
