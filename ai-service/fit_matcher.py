import math
import os
import re
from collections import Counter

MODEL_NAME = os.getenv("FIT_MODEL", "BAAI/bge-small-en-v1.5")
MEDIUM_EMBEDDING = float(os.getenv("FIT_MEDIUM_EMBEDDING", "0.62"))
MEDIUM_TFIDF = float(os.getenv("FIT_MEDIUM_TFIDF", "0.18"))

FIELDS = {
    "Agriculture": [r"agricultur", r"\bagri", r"agribusiness", r"horticultur", r"animal science", r"forestry",
                    r"fisher", r"aquacultur", r"veterinar", r"food technology", r"\bfarm", r"\bcrop"],
    "Engineering": [r"engineer"],
    "Computing": [r"information technology", r"computer", r"computing", r"information systems", r"data analytics",
                  r"software", r"programming", r"\bbsit\b"],
    "Sciences": [r"basic sciences?", r"applied sciences?", r"\bbiology", r"chemistry", r"physics", r"mathematic",
                 r"environmental science", r"marine science", r"geology", r"\bstatistics"],
    "Education": [r"(secondary|elementary|teacher|physical|mathematics|science|early childhood|special|technology and livelihood) education",
                  r"\bbsed\b", r"\bbeed\b", r"teacher", r"\bteach"],
    "Business": [r"accountan", r"accounting", r"business", r"management", r"economics", r"entrepreneur"],
    "Health": [r"nursing", r"\bhealth", r"medical", r"(?<!veterinary )medicine", r"pharmacy", r"midwifery"],
    "Arts and culture": [r"\barts?\b", r"\bculture", r"dance", r"music", r"theat", r"choir", r"\bband\b", r"visual"],
}

STOP = set("""a an and are as at be by for from has have in into is it its of on or that the this to with your you
our their student students program programs scholarship scholarships course courses college year level bachelor
science sciences bs ba university csu caraga state office oas apply applied directly agency through processed
funded financial assistance grant grants who must not other than any all""".split())

_model = None
_model_error = None


def _embedder():
    global _model, _model_error
    if _model is not None or _model_error is not None:
        return _model
    try:
        from fastembed import TextEmbedding

        _model = TextEmbedding(MODEL_NAME)
    except Exception as e:
        _model_error = f"{type(e).__name__}: {e}"
        _model = None
    return _model


def status():
    model = _embedder()
    return {"method": "embeddings" if model else "tfidf", "model": MODEL_NAME if model else None, "error": _model_error}


def fields_of(text):
    low = (text or "").lower()
    found = [name for name, patterns in FIELDS.items() if any(re.search(p, low) for p in patterns)]
    if "Computing" not in found and re.search(r"\bIT\b", text or ""):
        found = [f for f in FIELDS if f in found or f == "Computing"]
    return found


def _tokens(text):
    words = re.findall(r"[a-z]+", (text or "").lower())
    out = []
    for w in words:
        if len(w) < 3 or w in STOP:
            continue
        for suffix in ("ation", "ing", "ies", "al", "es", "s", "e"):
            if w.endswith(suffix) and len(w) - len(suffix) >= 4:
                w = w[: -len(suffix)]
                break
        out.append(w)
    return out


def _tfidf_vectors(texts):
    docs = [Counter(_tokens(t)) for t in texts]
    df = Counter(w for d in docs for w in d)
    n = len(docs)
    vectors = []
    for d in docs:
        v = {w: (1 + math.log(c)) * math.log((n + 1) / (df[w] + 1)) + 0.0 for w, c in d.items()}
        norm = math.sqrt(sum(x * x for x in v.values())) or 1.0
        vectors.append({w: x / norm for w, x in v.items()})
    return vectors


def _cosine_sparse(a, b):
    if len(a) > len(b):
        a, b = b, a
    return sum(x * b.get(w, 0.0) for w, x in a.items())


def _cosine_dense(a, b):
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a)) or 1.0
    nb = math.sqrt(sum(y * y for y in b)) or 1.0
    return dot / (na * nb)


def score(students, programs, use_model=True):
    texts = [s["text"] for s in students] + [p["text"] for p in programs]
    model = _embedder() if use_model else None
    if model is not None:
        vectors = [list(map(float, v)) for v in model.embed(texts)]
        similar = _cosine_dense
        method, medium = "embeddings", MEDIUM_EMBEDDING
    else:
        vectors = _tfidf_vectors(texts)
        similar = _cosine_sparse
        method, medium = "tfidf", MEDIUM_TFIDF

    s_vec, p_vec = vectors[: len(students)], vectors[len(students):]
    s_fields = [fields_of(s["text"]) for s in students]
    p_fields = [fields_of(p["text"]) for p in programs]
    s_words = [set(_tokens(s["text"])) for s in students]
    p_words = [set(_tokens(p["text"])) for p in programs]

    scores = []
    for i, student in enumerate(students):
        for j, program in enumerate(programs):
            sim = max(0.0, similar(s_vec[i], p_vec[j]))
            shared = [f for f in p_fields[j] if f in s_fields[i]]
            if not p_fields[j]:
                level = "any"
            elif shared:
                level = "high"
            elif sim >= medium:
                level = "medium"
            else:
                level = "low"
            scores.append({
                "student_id": student["id"],
                "program_id": program["id"],
                "level": level,
                "similarity": round(sim, 3),
                "fields": shared,
                "program_fields": p_fields[j],
                "words": sorted(s_words[i] & p_words[j])[:6],
            })
    return {"method": method, "model": MODEL_NAME if method == "embeddings" else None, "scores": scores}
