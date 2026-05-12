#!/bin/bash
echo "✅ Bale YouTube Downloader Image Ready"
echo "   yt-dlp version: $(yt-dlp --version 2>/dev/null || echo 'not found')"
echo "   ffmpeg version: $(ffmpeg -version 2>/dev/null | head -1 || echo 'not found')"
echo "   Deno version: $(deno --version 2>/dev/null | head -1 || echo 'not found')"
echo "   Python version: $(python3 --version 2>/dev/null || echo 'not found')"
echo ""
echo "All dependencies are pre-installed and ready!"
exec "$@"
