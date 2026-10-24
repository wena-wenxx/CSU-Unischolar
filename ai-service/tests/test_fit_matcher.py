"""Tests for fit_matcher.py (the built-in TF-IDF path; the embedding model is checked by hand). Fictional data."""
from fit_matcher import fields_of, score

PROGRAMS = [
    {"id": 1, "text": "DA-ACEF. For students taking agriculture, forestry, fisheries, veterinary medicine and related agricultural programs."},
    {"id": 2, "text": "NGCP. For engineering students, with priority to BS Electrical Engineering."},
    {"id": 3, "text": "TES. CHED-funded tertiary education subsidy processed through the OAS."},
    {"id": 4, "text": "DOST. Priority fields: basic sciences, engineering, applied sciences, and science and mathematics education."},
]


def level(result, student_id, program_id):
    return next(s for s in result["scores"] if s["student_id"] == student_id and s["program_id"] == program_id)


def test_the_word_it_is_not_information_technology():
    assert fields_of("The OAS posts updates when the agency requests it.") == []
    assert fields_of("agricultural, IT, industrial and civil engineering") == ["Agriculture", "Engineering", "Computing"]


def test_fields_ignore_bachelor_of_science():
    assert fields_of("Bachelor of Science in Nursing") == ["Health"]
    assert fields_of("Bachelor of Science in Information Technology") == ["Computing"]
    assert "Sciences" not in fields_of("Bachelor of Science in Accountancy")


def test_shared_field_is_high_other_field_low_general_program_any():
    students = [
        {"id": 10, "text": "Bachelor of Science in Agriculture, College of Agriculture and Agri-Industries. I want to work in crop research."},
        {"id": 11, "text": "Bachelor of Science in Civil Engineering, College of Engineering and Geo-Sciences."},
        {"id": 12, "text": "Bachelor of Science in Nursing, College of Nursing."},
    ]
    result = score(students, PROGRAMS, use_model=False)
    assert result["method"] == "tfidf"
    assert level(result, 10, 1)["level"] == "high" and level(result, 10, 1)["fields"] == ["Agriculture"]
    assert level(result, 11, 2)["level"] == "high" and level(result, 11, 4)["level"] == "high"
    assert level(result, 12, 1)["level"] == "low"
    assert level(result, 12, 3)["level"] == "any"          # open to all courses: not a "low fit"
    # the similarity explains the ranking: agriculture student is closer to DA-ACEF than to NGCP
    assert level(result, 10, 1)["similarity"] > level(result, 10, 2)["similarity"]


def test_goals_text_adds_a_field():
    student = [{"id": 20, "text": "Bachelor of Secondary Education. I want to teach mathematics."}]
    result = score(student, PROGRAMS, use_model=False)
    assert level(result, 20, 4)["level"] == "high"
    assert "Education" in level(result, 20, 4)["fields"]
