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
    echo "[ollama-init] $MODEL is already here"
else
    echo "[ollama-init] pulling $MODEL - a couple of GB, only happens once"
    ollama pull "$MODEL"
    echo "[ollama-init] $MODEL is ready"
fi

# Load it into memory now, so the first ticket does not wait 1-3 minutes for
# it. How long it then stays is the server's OLLAMA_KEEP_ALIVE: for good in
# production, the 5 minute default elsewhere.
if ollama run "$MODEL" "" >/dev/null 2>&1; then
    echo "[ollama-init] $MODEL loaded into memory"
else
    echo "[ollama-init] could not preload $MODEL - the first ticket will load it"
fi

# Production restarts this container with the box, so the model is loaded
# again after every reboot. Between reboots it just waits here.
if [ "${OLLAMA_INIT_STAY:-no}" = "yes" ]; then
    exec sleep infinity
fi
