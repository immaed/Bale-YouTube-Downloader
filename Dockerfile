# ============================================================
# Dockerfile — Bale YouTube Downloader
# Image: khashayardev/bale-yt-downloader
# تمام وابستگی‌های yt-dl.yml از قبل نصب شده
# ============================================================

FROM ubuntu:22.04

# جلوگیری از سوال‌های تعاملی
ENV DEBIAN_FRONTEND=noninteractive

# نصب تمام وابستگی‌ها در یک لایه
RUN apt-get update -qq && \
    apt-get install -y -qq \
    python3-pip \
    ffmpeg \
    zip \
    p7zip-full \
    curl \
    jq \
    bc \
    unzip \
    git \
    docker.io \
    ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# نصب yt-dlp
RUN pip3 install --upgrade yt-dlp --quiet

# نصب Deno (برای دور زدن تحریم یوتیوب)
RUN curl -fsSL https://deno.land/install.sh | sh && \
    cp /root/.deno/bin/deno /usr/local/bin/deno

# تنظیمات Git (برای push کردن)
RUN git config --global http.postBuffer 524288000 && \
    git config --global http.maxRequestBuffer 100M && \
    git config --global core.compression 0 && \
    git config --global pack.windowMemory 256m && \
    git config --global pack.packSizeLimit 100m && \
    git config --global pack.threads 1

# پوشه کار
WORKDIR /workspace

# اسکریپت ورودی (اختیاری - برای تست)
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
