# rewrites the provisioned n8n workflows so the cloud branch runs on openrouter
import json
import sys
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "provision" / "workflows"

RENAMES = {
    "Classify (Gemini)": "Classify (Cloud)",
    "Assistant (Gemini)": "Assistant (Cloud)",
    "Tag Source: Gemini": "Tag Source: Cloud",
    "Gemini answered?": "Cloud answered?",
    "Retry Gemini once?": "Retry cloud once?",
    "Google Gemini Chat Model": "OpenRouter Chat Model",
}

CRED = {"openRouterApi": {"id": "HelpdeskOpenRouter1", "name": "Helpdesk OpenRouter (cloud)"}}

ROUTE_SYSTEM = (
    "You route helpdesk tickets. Pick the ONE team whose handles list covers the request, "
    "and return its name exactly as written in the list. Reply with the team name only."
)


def rename_all(text: str) -> str:
    for old, new in RENAMES.items():
        text = text.replace(old, new)
    return text


def openrouter_model_node(node: dict) -> dict:
    node["type"] = "@n8n/n8n-nodes-langchain.lmChatOpenRouter"
    node["typeVersion"] = 1
    node["parameters"] = {"model": "__OPENROUTER_MODEL__", "options": {"temperature": 0.2, "timeout": 90000, "maxRetries": 1}}
    node["credentials"] = CRED
    return node


def route_cloud_node(position: list) -> dict:
    body = (
        "={{ JSON.stringify({ model: '__OPENROUTER_MODEL__', temperature: 0, max_tokens: 40, messages: [ "
        "{ role: 'system', content: '" + ROUTE_SYSTEM + "' }, "
        "{ role: 'user', content: $('Webhook').first().json.body.routing_prompt } ] }) }}"
    )
    return {
        "parameters": {
            "method": "POST",
            "url": "https://openrouter.ai/api/v1/chat/completions",
            "authentication": "predefinedCredentialType",
            "nodeCredentialType": "openRouterApi",
            "sendHeaders": True,
            "headerParameters": {"parameters": [{"name": "X-Title", "value": "__BRAND_NAME__"}]},
            "sendBody": True,
            "specifyBody": "json",
            "jsonBody": body,
            "options": {"timeout": 60000},
        },
        "id": str(uuid.uuid4()),
        "name": "Route (Cloud)",
        "type": "n8n-nodes-base.httpRequest",
        "typeVersion": 4.2,
        "position": position,
        "onError": "continueRegularOutput",
        "credentials": CRED,
    }


def if_node(name: str, expr: str, position: list) -> dict:
    return {
        "parameters": {
            "conditions": {
                "options": {"caseSensitive": True, "leftValue": "", "typeValidation": "strict", "version": 3},
                "conditions": [{
                    "id": str(uuid.uuid4()),
                    "leftValue": expr,
                    "rightValue": "",
                    "operator": {"type": "boolean", "operation": "true", "singleValue": True},
                }],
                "combinator": "and",
            },
            "options": {},
        },
        "id": str(uuid.uuid4()),
        "name": name,
        "type": "n8n-nodes-base.if",
        "typeVersion": 2.3,
        "position": position,
    }


def link(target: str) -> list:
    return [{"node": target, "type": "main", "index": 0}]


def convert(path: Path) -> None:
    raw = json.loads(path.read_text(encoding="utf-8"))
    wf = raw[0] if isinstance(raw, list) else raw

    wf = json.loads(rename_all(json.dumps(wf, ensure_ascii=False)))

    for node in wf["nodes"]:
        if node["name"] == "OpenRouter Chat Model":
            openrouter_model_node(node)
        if node["name"] == "Tag Source: Cloud":
            node["parameters"]["assignments"]["assignments"][0]["value"] = "OpenRouter"
        if node["name"] == "Normalize & Enrich":
            code = node["parameters"]["jsCode"]
            old = ("let routedTeam = '';\ntry { routedTeam = String(((($('Route (Local)').first() || {}).json || {}).message || {}).content || '').trim(); } catch (e) { routedTeam = ''; }")
            new = ("let routedTeam = '';\n"
                   "try { routedTeam = String(((((($('Route (Cloud)').first() || {}).json || {}).choices || [])[0] || {}).message || {}).content || '').trim(); } catch (e) { routedTeam = ''; }\n"
                   "if (!routedTeam) { try { routedTeam = String(((($('Route (Local)').first() || {}).json || {}).message || {}).content || '').trim(); } catch (e) { routedTeam = ''; } }")
            if old not in code:
                sys.exit("routing read not found in Normalize & Enrich")
            code = code.replace(old, new)
            code = code.replace("'To be confirmed with the requester - not provided in the request'", "''")
            node["parameters"]["jsCode"] = code

    names = {n["name"] for n in wf["nodes"]}

    if "Route (Local)" in names and "Route (Cloud)" not in names:
        local = next(n for n in wf["nodes"] if n["name"] == "Route (Local)")
        x, y = local["position"]
        allow = "={{ $('Webhook').first().json.body.allow_escalation === 'Yes' }}"
        routed = "={{ !!($json.choices && $json.choices[0] && $json.choices[0].message && String($json.choices[0].message.content || '').trim()) }}"
        wf["nodes"].append(if_node("Route in cloud?", allow, [x - 220, y - 160]))
        wf["nodes"].append(route_cloud_node([x, y - 160]))
        wf["nodes"].append(if_node("Cloud routed?", routed, [x + 220, y - 160]))

        conn = wf["connections"]
        after_route = conn["Route (Local)"]["main"][0][0]["node"]
        conn["Check routing?"]["main"][0] = link("Route in cloud?")
        conn["Route in cloud?"] = {"main": [link("Route (Cloud)"), link("Route (Local)")]}
        conn["Route (Cloud)"] = {"main": [link("Cloud routed?")]}
        conn["Cloud routed?"] = {"main": [link(after_route), link("Route (Local)")]}

    wf["name"] = rename_all(wf.get("name", ""))
    out = [wf] if isinstance(raw, list) else wf
    path.write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print("converted", path.name)


for name in ("submit-ticket.json", "ticket-chat.json"):
    convert(ROOT / name)
