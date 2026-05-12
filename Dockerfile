# ============================================================
# Dockerfile — Bale YouTube Downloader
# Image: khashayardev/bale-yt-downloader
# 
# تمام وابستگی‌های yt-dl.yml | yt-search.yml | cleanup.yml
# از پیش نصب‌شده برای اجرای فوق‌سریع workflowها
# ============================================================

FROM ubuntu:22.04

# جلوگیری از سوال‌های تعاملی APT
ENV DEBIAN_FRONTEND=noninteractive

# ══════════════════════════════════════════════════════════
# لایه ۱: بسته‌های سیستمی
# ══════════════════════════════════════════════════════════
RUN apt-get update -qq && \
    apt-get install -y -qq \
    # Shell
    bash \
    # Docker (مورد نیاز برای اجرای Cloudflare WARP داخل container)
    docker.io \
    # Python + Pip
    python3 \
    python3-pip \
    # ffmpeg (پردازش ویدیو)
    ffmpeg \
    # ابزارهای فشرده‌سازی
    zip \
    p7zip-full \
    # ابزارهای خط فرمان
    curl \
    jq \
    bc \
    unzip \
    wget \
    # Git (checkout و push)
    git \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# ══════════════════════════════════════════════════════════
# لایه ۲: yt-dlp (دانلودر یوتیوب)
# ══════════════════════════════════════════════════════════
RUN pip3 install --upgrade --quiet \
    yt-dlp \
    && rm -rf /root/.cache/pip

# ══════════════════════════════════════════════════════════
# لایه ۳: Deno (موتور JavaScript — برای دور زدن تحریم یوتیوب)
# ══════════════════════════════════════════════════════════
RUN curl -fsSL https://deno.land/install.sh | sh && \
    cp /root/.deno/bin/deno /usr/local/bin/deno && \
    chmod +x /usr/local/bin/deno

# ══════════════════════════════════════════════════════════
# لایه ۴: تنظیمات سراسری Git
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
# پایان: پوشه کاری + تأیید نهایی
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
    echo "docker : $(docker --version 2>&1 || echo 'CLI only')" && \
    echo "bash   : $(bash --version | head -1)" && \
    echo "=====================================" && \
    echo "✅ All dependencies OK"
