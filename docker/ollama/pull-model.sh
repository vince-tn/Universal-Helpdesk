set -e

MODEL="${OLLAMA_MODEL:-qwen2.5:3b}"

echo "[ollama-init] waiting for the ollama server at ${OLLAMA_HOST}"
tries=0
until ollama list >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -gt 90 ]; then
        echo "[ollama-init] the server never answered - giving up"
        exit 1
    fi
    sleep 2
done

if ollama list | awk 'NR > 1 { print $1 }' | grep -qx "$MODEL"; then
    echo "[ollama-init] $MODEL is already here - nothing to do"
    exit 0
fi

echo "[ollama-init] pulling $MODEL - a couple of GB, only happens once"
ollama pull "$MODEL"
echo "[ollama-init] $MODEL is ready"
