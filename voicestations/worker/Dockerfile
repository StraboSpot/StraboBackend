# Voice Stations worker (build step 2, docs/AlternateStraboFieldIdea/Phase1_Plan.md):
# Python worker + resident whisper-server (whisper.cpp) + ffmpeg in one image.
#   FLAVOR=cuda  NVIDIA GPU host (gpubox, RTX 3060 = CUDA arch 86)
#   FLAVOR=cpu   prod fallback / Mac dev (built natively on the host that runs it)
# Build: docker build --build-arg FLAVOR=cuda -t strabo-voiceworker:cuda .
ARG FLAVOR=cpu
ARG WHISPER_VERSION=v1.9.4

# ---- whisper-server, CUDA build
FROM nvidia/cuda:12.6.3-devel-ubuntu24.04 AS build-cuda
ARG WHISPER_VERSION
ARG CUDA_ARCH=86
# Ubuntu's archive over HTTPS: plain HTTP to it timed out from gpubox's network (10-07)
RUN sed -i 's#http://#https://#g' /etc/apt/sources.list.d/ubuntu.sources && apt-get update && apt-get install -y --no-install-recommends git cmake build-essential ca-certificates \
 && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 --branch ${WHISPER_VERSION} https://github.com/ggml-org/whisper.cpp /src
RUN cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
      -DGGML_CUDA=ON -DCMAKE_CUDA_ARCHITECTURES=${CUDA_ARCH} -DWHISPER_BUILD_TESTS=OFF \
 && cmake --build /build -j"$(nproc)" --target whisper-server

# ---- whisper-server, CPU build
FROM debian:bookworm-slim AS build-cpu
ARG WHISPER_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends git cmake build-essential ca-certificates \
 && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 --branch ${WHISPER_VERSION} https://github.com/ggml-org/whisper.cpp /src
# Native CPU tuning detects wrongly in Docker's Linux VM on Apple silicon (gcc
# rejects the fp16 intrinsics), so ARM builds name the architecture instead.
RUN ARM=""; [ "$(uname -m)" = "aarch64" ] && ARM="-DGGML_NATIVE=OFF -DGGML_CPU_ARM_ARCH=armv8.2-a+fp16+dotprod"; \
    cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DWHISPER_BUILD_TESTS=OFF $ARM \
 && cmake --build /build -j"$(nproc)" --target whisper-server

# ---- runtimes
FROM nvidia/cuda:12.6.3-runtime-ubuntu24.04 AS runtime-cuda
RUN sed -i 's#http://#https://#g' /etc/apt/sources.list.d/ubuntu.sources && apt-get update && apt-get install -y --no-install-recommends python3 python3-venv ffmpeg libgomp1 ca-certificates \
 && rm -rf /var/lib/apt/lists/*

FROM python:3.12-slim-bookworm AS runtime-cpu
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg libgomp1 ca-certificates \
 && rm -rf /var/lib/apt/lists/*

FROM build-${FLAVOR} AS build

FROM runtime-${FLAVOR}
ARG FLAVOR
ARG WHISPER_VERSION
COPY --from=build /build/bin/whisper-server /usr/local/bin/whisper-server
COPY requirements.txt /app/requirements.txt
RUN python3 -m venv /opt/venv && /opt/venv/bin/pip install --no-cache-dir -r /app/requirements.txt
COPY vsworker /app/vsworker
COPY tests /app/tests
RUN useradd --uid 10001 --create-home --shell /usr/sbin/nologin worker
USER worker
WORKDIR /app
ENV PATH=/opt/venv/bin:$PATH PYTHONUNBUFFERED=1 VS_FLAVOR=${FLAVOR} VS_WHISPER_VERSION=${WHISPER_VERSION}
CMD ["python", "-m", "vsworker"]
