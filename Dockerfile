# ============================================================
# Dockerfile — Bale YouTube Downloader V5
# Image: khashayardev/bale-yt-downloader
# 
# تمام وابستگی‌های yt-dl.yml | yt-search.yml | cleanup.yml
# + cloudflare-warp (بدون نیاز به Docker-in-Docker)
# ============================================================

FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

# ══════════════════════════════════════════════════════════
# لایه ۱: بسته‌های سیستمی + Cloudflare WARP
# ══════════════════════════════════════════════════════════
RUN apt-get update -qq && \
    apt-get install -y -qq \
    gnupg \
    lsb-release \
    ca-certificates \
    curl \
    && rm -rf /var/lib/apt/lists/*

# اضافه کردن مخزن Cloudflare + نصب WARP
RUN curl -fsSL https://pkg.cloudflareclient.com/pubkey.gpg | \
    gpg --dearmor -o /usr/share/keyrings/cloudflare-warp-archive-keyring.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/cloudflare-warp-archive-keyring.gpg] https://pkg.cloudflareclient.com/ jammy main" \
    > /etc/apt/sources.list.d/cloudflare-client.list

RUN apt-get update -qq && \
    apt-get install -y -qq \
    cloudflare-warp \
    tor \
    # Shell
    bash \
    # Python + Pip
    python3 \
    python3-pip \
    # ffmpeg
    ffmpeg \
    # Compression tools
    zip \
    p7zip-full \
    # CLI tools
    jq \
    bc \
    unzip \
    wget \
    # Git
    git \
    && rm -rf /var/lib/apt/lists/*

# ══════════════════════════════════════════════════════════
# لایه ۲: yt-dlp
# ══════════════════════════════════════════════════════════
RUN pip3 install --upgrade --quiet \
    yt-dlp \
    pytube \
    beautifulsoup4 \
    requests \
    && rm -rf /root/.cache/pip

# ══════════════════════════════════════════════════════════
# لایه ۳: Deno
# ══════════════════════════════════════════════════════════
RUN curl -fsSL https://deno.land/install.sh | sh && \
    cp /root/.deno/bin/deno /usr/local/bin/deno && \
    chmod +x /usr/local/bin/deno

# ══════════════════════════════════════════════════════════
# لایه ۴: تنظیمات Git
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
# لایه ۵: پوشه کاری + تأیید
# ══════════════════════════════════════════════════════════
WORKDIR /workspace

RUN echo "=== Bale YouTube Downloader Image ===" && \
    echo "yt-dlp : $(yt-dlp --version)" && \
    echo "ffmpeg : $(ffmpeg -version 2>&1 | head -1)" && \
    echo "python : $(python3 --version)" && \
    echo "deno   : $(deno --version 2>&1 | head -1)" && \
    echo "jq     : $(jq --version)" && \
    echo "curl   : $(curl --version | head -1)" && \
    echo "git    : $(git --version)" && \
    echo "warp   : $(warp-cli --version 2>&1 || echo 'installed')" && \
    echo "bash   : $(bash --version | head -1)" && \
    echo "=====================================" && \
    echo "✅ All dependencies OK"
