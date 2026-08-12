set -e

PROVISION_DIR=/provision
STATE_DIR=/home/node/.n8n
MARKER="$STATE_DIR/.helpdesk-provisioned"
WORK=/tmp/helpdesk-provision

SUBMIT_ID=HelpdeskSubmit01
CHAT_ID=HelpdeskChat0001

OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5:3b}"
GEMINI_MODEL="${GEMINI_MODEL:-models/gemini-2.5-flash}"

log() { echo "[provision] $*"; }

mkdir -p "$WORK" "$STATE_DIR"

# ---------------------------------------------------------------- credentials
if [ ! -f "$MARKER" ]; then
    log "seeding the Ollama credential (http://ollama:11434)"
    n8n import:credentials --input="$PROVISION_DIR/credentials/ollama.json" \
        || log "WARNING: could not import the Ollama credential"
fi

if [ -n "$GEMINI_API_KEY" ]; then
    log "seeding the Gemini credential from GEMINI_API_KEY"
    sed "s|__GEMINI_API_KEY__|${GEMINI_API_KEY}|" \
        "$PROVISION_DIR/credentials/gemini.json" > "$WORK/gemini.json"
    n8n import:credentials --input="$WORK/gemini.json" \
        || log "WARNING: could not import the Gemini credential"
    rm -f "$WORK/gemini.json"
else
    log "GEMINI_API_KEY is empty - running local-only, on Ollama"
fi

existing=$(n8n list:workflow --onlyId 2>/dev/null || true)

import_workflow() {
    file="$1"
    id="$2"
    label="$3"

    if [ "$HELPDESK_FORCE_IMPORT" != "true" ] && echo "$existing" | grep -q "^${id}$"; then
        log "$label is already here ($id) - leaving your copy untouched"
        return 0
    fi

    log "importing $label ($id)"
    sed -e "s|__OLLAMA_MODEL__|${OLLAMA_MODEL}|g" \
        -e "s|__GEMINI_MODEL__|${GEMINI_MODEL}|g" \
        "$file" > "$WORK/${id}.json"
    n8n import:workflow --input="$WORK/${id}.json" \
        || log "WARNING: could not import $label"
    rm -f "$WORK/${id}.json"
}

import_workflow "$PROVISION_DIR/workflows/submit-ticket.json" "$SUBMIT_ID" "Submit Ticket"
import_workflow "$PROVISION_DIR/workflows/ticket-chat.json" "$CHAT_ID" "Ticket Chat"

free_the_path() {
    log "a published workflow is holding one of our webhook paths - standing it down"
    for other in $(n8n list:workflow --onlyId 2>/dev/null || true); do
        [ "$other" = "$SUBMIT_ID" ] && continue
        [ "$other" = "$CHAT_ID" ] && continue
        n8n unpublish:workflow --id="$other" >/dev/null 2>&1 \
            && log "unpublished $other"
    done
}

exists() {
    n8n list:workflow --onlyId 2>/dev/null | grep -q "^${1}$"
}

publish() {
    id="$1"

    if ! exists "$id"; then
        return 1
    fi

    n8n publish:workflow --id="$id" >/dev/null 2>&1 && return 0
    free_the_path
    n8n publish:workflow --id="$id" >/dev/null 2>&1
}

for id in "$SUBMIT_ID" "$CHAT_ID"; do
    if publish "$id"; then
        log "published $id"
    elif exists "$id"; then
        log "WARNING: $id would not publish - its webhook will 404 until it does"
    else
        log "WARNING: $id was never imported - see the import error above. Its webhook will 404."
    fi
done

touch "$MARKER"
log "webhooks live: POST /webhook/submit-ticket and POST /webhook/ticket-chat"

exec n8n start
