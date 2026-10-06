# CSU UniScholar AI service (FastAPI + PaddleOCR) - container for a
# Hugging Face Docker Space (free "CPU basic" hardware, port 7860).
FROM python:3.11-slim

# Libraries PaddleOCR / OpenCV need at runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 libgomp1 \
    && rm -rf /var/lib/apt/lists/*

# Hugging Face runs the container as user 1000
RUN useradd -m -u 1000 user
USER user
ENV HOME=/home/user \
    PATH=/home/user/.local/bin:$PATH \
    PYTHONUNBUFFERED=1
WORKDIR /home/user/app

COPY --chown=user requirements.txt requirements-ocr.txt ./
RUN pip install --no-cache-dir --user -r requirements.txt -r requirements-ocr.txt

COPY --chown=user . .

EXPOSE 7860
CMD ["sh", "-c", "uvicorn main:app --host 0.0.0.0 --port ${PORT:-7860}"]
