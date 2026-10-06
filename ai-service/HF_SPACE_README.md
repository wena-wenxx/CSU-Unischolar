---
title: CSU UniScholar AI
emoji: 📄
colorFrom: green
colorTo: yellow
sdk: docker
app_port: 7860
pinned: false
---

# CSU UniScholar - AI document checker

FastAPI service that reads uploaded scholarship documents (PDF text or
PaddleOCR) and flags possible problems for OAS staff. It never approves or
rejects anything; staff make every decision.

Capstone prototype (Caraga State University, 2026). Demo data is fictional.

When you create the Hugging Face Space, copy this file into the Space as
`README.md` (the block between the `---` lines tells Hugging Face to build
the Dockerfile and open port 7860).
