# ============================================================
# Dockerfile — Bale YouTube Downloader (Complete)
# Image: khashayardev/bale-yt-downloader
# 
# شامل تمام وابستگی‌های:
# - yt-dl.yml (دانلودر اصلی)
# - yt-search.yml (موتور جستجو)
# - cleanup.yml (پاکسازی خودکار)
# 
# نصب‌شده در زمان build — عدم نیاز به apt-get در runtime
# ============================================================

FROM ubuntu:22.04

# جلوگیری از سوال‌های تعاملی
ENV DEBIAN_FRONTEND=noninteractive

# ══════════════════════════════════════════════════════════
# لایه ۱: بسته‌های سیستمی
# ══════════════════════════════════════════════════════════
RUN apt-get update -qq && \
    apt-get install -y -qq \
    # === yt-dl.yml نیازمندی‌های ===
    python3 \
    python3-pip \
    ffmpeg \
    zip \
    p7zip-full \
    curl \
    jq \
    bc \
    unzip \
    # === yt-search.yml نیازمندی‌های ===
    # (curl, jq, bc قبلاً هستن)
    # === عمومی ===
    git \
    ca-certificates \
    wget \
    # === پاکسازی ===
    && rm -rf /var/lib/apt/lists/*

# ══════════════════════════════════════════════════════════
# لایه ۲: Python packages
# ══════════════════════════════════════════════════════════
RUN pip3 install --upgrade --quiet \
    yt-dlp \
    && rm -rf /root/.cache/pip

# ══════════════════════════════════════════════════════════
# لایه ۳: Deno (برای yt-dl.yml دور زدن تحریم)
# ══════════════════════════════════════════════════════════
RUN curl -fsSL https://deno.land/install.sh | sh && \
    cp /root/.deno/bin/deno /usr/local/bin/deno && \
    chmod +x /usr/local/bin/deno

# ══════════════════════════════════════════════════════════
# لایه ۴: تنظیمات Git (برای push در yt-dl.yml)
# ══════════════════════════════════════════════════════════
RUN git config --global http.postBuffer 524288000 && \
    git config --global http.maxRequestBuffer 100M && \
    git config --global core.compression 0 && \
    git config --global pack.windowMemory 256m && \
    git config --global pack.packSizeLimit 100m && \
    git config --global pack.threads 1 && \
    git config --global user.name "github-actions[bot]" && \
    git config --global user.email "github-actions[bot]@users.noreply.github.com"

# ══════════════════════════════════════════════════════════
# لایه ۵: پوشه کاری
# ══════════════════════════════════════════════════════════
WORKDIR /workspace

# ══════════════════════════════════════════════════════════
# لایه ۶: تأیید صحت نصب
# ══════════════════════════════════════════════════════════
RUN echo "=== Installed Tools ===" && \
    echo "yt-dlp: $(yt-dlp --version)" && \
    echo "ffmpeg: $(ffmpeg -version 2>&1 | head -1)" && \
    echo "Python: $(python3 --version)" && \
    echo "Deno: $(deno --version 2>&1 | head -1)" && \
    echo "jq: $(jq --version)" && \
    echo "curl: $(curl --version | head -1)" && \
    echo "git: $(git --version)" && \
    echo "=======================" && \
    echo "✅ All dependencies OK"
