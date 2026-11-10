#!/usr/bin/env python3
"""
Tibber Data API -> MQTT (LoxBerry-Plugin "Tibber Data API")

Liest alle Homes und alle Geräte (E-Autos, Wallboxen, Wärmepumpen/Thermostate,
Wechselrichter, Heimspeicher), die über die Tibber Data API verfügbar sind,
und veröffentlicht jede Capability und jedes Attribut als eigenes MQTT-Topic.

Nur Python-Standardbibliothek, keine zusätzlichen Pakete nötig.

Aufruf:
  tibber_data_mqtt.py                 einmal abfragen und an MQTT senden
  tibber_data_mqtt.py --cron          wie oben, aber nur wenn aktiviert und das Intervall abgelaufen ist
  tibber_data_mqtt.py --auth          einmalige Anmeldung bei Tibber in der Konsole
  tibber_data_mqtt.py --auth-start    Anmelde-Adresse erzeugen (für die Weboberfläche)
  tibber_data_mqtt.py --auth-finish URL   Anmeldung mit der weitergeleiteten Adresse abschließen
  tibber_data_mqtt.py --dump          Rohdaten der API als JSON anzeigen
  tibber_data_mqtt.py --dry-run       Topics anzeigen, aber nichts senden

Optionen: --config PFAD, --debug
"""

import argparse
import fcntl
import base64
import datetime as dt
import hashlib
import json
import os
import re
import secrets
import socket
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
VERSION = "0.9.0"

# Diese Platzhalter ersetzt LoxBerry bei der Installation durch die Plugin-Ordner.
CONFIG_DIR = "REPLACELBPCONFIGDIR"
DATA_DIR = "REPLACELBPDATADIR"
LOG_DIR = "REPLACELBPLOGDIR"
for _name in ("CONFIG_DIR", "DATA_DIR", "LOG_DIR"):
    if globals()[_name].startswith("REPLACE"):  # Entwicklung / Aufruf außerhalb von LoxBerry
        globals()[_name] = os.environ.get("TIBBERDATA_" + _name, SCRIPT_DIR)
LOG_FILE = os.path.join(LOG_DIR, "tibberdata.log")
STATUS_FILE = os.path.join(DATA_DIR, "status.json")
PENDING_FILE = os.path.join(DATA_DIR, "auth_pending.json")
LOCK_FILE = os.path.join(DATA_DIR, "run.lock")

DEFAULTS = {
    "enabled": True,
    "interval_min": 5,
    "client_id": "",
    "client_secret": "",
    "redirect_uri": "http://localhost:8080/callback",
    "scopes": [
        "openid", "profile", "email", "offline_access",
        "data-api-user-read", "data-api-homes-read",
        "data-api-vehicles-read", "data-api-chargers-read",
        "data-api-thermostats-read", "data-api-energy-systems-read",
        "data-api-inverters-read",
    ],
    "auth_url": "https://thewall.tibber.com/connect/authorize",
    "token_url": "https://thewall.tibber.com/connect/token",
    "api_base": "https://data-api.tibber.com/v1",
    "token_file": "tokens.json",
    "topic_prefix": "tibberdata",
    "retain": True,
    "publish_attributes": True,
    "publish_units": False,
    # MQTT: leer lassen = automatisch aus der LoxBerry-Konfiguration lesen
    "mqtt_host": "",
    "mqtt_port": 0,
    "mqtt_user": "",
    "mqtt_pass": "",
    "http_timeout": 30,
}

# Wörter, die zusätzlich als 1/0 unter <topic>_num veröffentlicht werden
TRUE_WORDS = {"connected", "charging", "on", "true", "yes", "online", "active",
              "heating", "running", "enabled", "plugged", "pluggedin", "home"}
FALSE_WORDS = {"disconnected", "idle", "off", "false", "no", "offline", "inactive",
               "notcharging", "stopped", "disabled", "unplugged", "away", "standby"}

# Diese Attribut-Felder werden nicht veröffentlicht (Netzwerkdetails)
SKIP_ATTR_FIELDS = {"ssid", "bssid", "ipAddress", "macAddress", "$type"}

DEBUG = False


LOG_TO_FILE = False  # bei Aufrufen aus der Weboberfläche zusätzlich ins Log schreiben


def log(msg):
    line = f"{dt.datetime.now():%Y-%m-%d %H:%M:%S} {msg}"
    print(line, flush=True)
    if LOG_TO_FILE:
        try:
            with open(LOG_FILE, "a", encoding="utf-8") as f:
                f.write(line + "\n")
        except OSError:
            pass


def debug(msg):
    if DEBUG:
        log("DEBUG " + msg)


# --------------------------------------------------------------------------- Config

class UserError(Exception):
    """Fehler mit verständlicher Meldung für Weboberfläche und Log."""


def load_config(path):
    cfg = dict(DEFAULTS)
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            cfg.update({k: v for k, v in json.load(f).items() if v is not None})
    if not os.path.isabs(cfg["token_file"]):
        cfg["token_file"] = os.path.join(os.path.dirname(os.path.abspath(path)), cfg["token_file"])
    return cfg


def require_client(cfg):
    if not cfg["client_id"] or not cfg["client_secret"]:
        raise UserError("Client-ID und Client-Secret fehlen – bitte in den Plugin-Einstellungen eintragen.")


def _ci_get(d, *keys):
    """Case-insensitive Zugriff auf verschachtelte Dicts."""
    for k in keys:
        if not isinstance(d, dict):
            return None
        match = next((v for kk, v in d.items() if kk.lower() == k.lower()), None)
        d = match
    return d


def loxberry_mqtt_settings():
    """Broker-Zugangsdaten aus der LoxBerry-Konfiguration (LB 3.x, Fallback LB 2.x Plugin)."""
    home = os.environ.get("LBHOMEDIR", "/opt/loxberry")
    # LoxBerry 3: MQTT ist Teil des Systems -> general.json
    try:
        with open(os.path.join(home, "config/system/general.json"), encoding="utf-8") as f:
            m = _ci_get(json.load(f), "Mqtt")
        if m:
            return {
                "host": _ci_get(m, "Brokerhost") or "localhost",
                "port": int(_ci_get(m, "Brokerport") or 1883),
                "user": _ci_get(m, "Brokeruser") or "",
                "pass": _ci_get(m, "Brokerpass") or "",
            }
    except (OSError, ValueError):
        pass
    # LoxBerry 2: MQTT Gateway Plugin
    try:
        base = os.path.join(home, "config/plugins/mqttgateway")
        with open(os.path.join(base, "mqtt.json"), encoding="utf-8") as f:
            addr = _ci_get(json.load(f), "Main", "brokeraddress") or "localhost:1883"
        user = pw = ""
        try:
            with open(os.path.join(base, "cred.json"), encoding="utf-8") as f:
                c = _ci_get(json.load(f), "Credentials") or {}
            user, pw = _ci_get(c, "brokeruser") or "", _ci_get(c, "brokerpass") or ""
        except (OSError, ValueError):
            pass
        host, _, port = addr.partition(":")
        return {"host": host or "localhost", "port": int(port or 1883), "user": user, "pass": pw}
    except (OSError, ValueError):
        pass
    return {"host": "localhost", "port": 1883, "user": "", "pass": ""}


def mqtt_settings(cfg):
    s = loxberry_mqtt_settings()
    if cfg["mqtt_host"]:
        s["host"] = cfg["mqtt_host"]
    if cfg["mqtt_port"]:
        s["port"] = int(cfg["mqtt_port"])
    if cfg["mqtt_user"]:
        s["user"] = cfg["mqtt_user"]
    if cfg["mqtt_pass"]:
        s["pass"] = cfg["mqtt_pass"]
    return s


# --------------------------------------------------------------------------- HTTP

class ApiError(Exception):
    def __init__(self, msg, status=None):
        super().__init__(msg)
        self.status = status


def http_request(url, data=None, headers=None, timeout=30):
    headers = {"User-Agent": f"loxberry-tibber-data-mqtt/{VERSION}", "Accept": "application/json",
               **(headers or {})}
    body = None
    if data is not None:
        body = urllib.parse.urlencode(data).encode()
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    req = urllib.request.Request(url, data=body, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")[:500]
        raise ApiError(f"HTTP {e.code} bei {url}: {detail}", e.code) from None
    except urllib.error.URLError as e:
        raise ApiError(f"Verbindung zu {url} fehlgeschlagen: {e.reason}") from None


# --------------------------------------------------------------------------- OAuth

def write_json(path, obj, private=False):
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    tmp = path + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600 if private else 0o644)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(obj, f, indent=2, ensure_ascii=False)
    os.replace(tmp, path)


def read_json(path, default=None):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def save_tokens(cfg, tokens):
    write_json(cfg["token_file"], tokens, private=True)


def load_tokens(cfg):
    try:
        with open(cfg["token_file"], encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        raise UserError("Noch nicht mit Tibber verbunden – bitte im Plugin auf „Mit Tibber verbinden“ klicken.")


def store_token_response(cfg, resp, previous=None):
    if "access_token" not in resp:
        raise ApiError(f"Unerwartete Token-Antwort: {resp}")
    tokens = {
        "access_token": resp["access_token"],
        # Tibber rotiert Refresh-Tokens: immer den neuesten speichern
        "refresh_token": resp.get("refresh_token") or (previous or {}).get("refresh_token"),
        "expires_at": time.time() + int(resp.get("expires_in", 3600)),
        "scope": resp.get("scope", ""),
    }
    if not tokens["refresh_token"]:
        raise ApiError("Kein Refresh-Token erhalten (Scope offline_access fehlt?)")
    save_tokens(cfg, tokens)
    return tokens


def auth_start(cfg):
    """PKCE-Verifier + State erzeugen, merken und Anmelde-Adresse zurückgeben."""
    require_client(cfg)
    verifier = secrets.token_urlsafe(64)[:96]
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
    state = secrets.token_urlsafe(16)
    write_json(PENDING_FILE, {"verifier": verifier, "state": state, "created": time.time()}, private=True)
    params = {
        "response_type": "code",
        "client_id": cfg["client_id"],
        "redirect_uri": cfg["redirect_uri"],
        "scope": " ".join(cfg["scopes"]),
        "state": state,
        "code_challenge": challenge,
        "code_challenge_method": "S256",
    }
    return cfg["auth_url"] + "?" + urllib.parse.urlencode(params, quote_via=urllib.parse.quote)


def auth_finish(cfg, answer):
    """Weitergeleitete Adresse (oder nur den Code) gegen Tokens tauschen."""
    require_client(cfg)
    pending = read_json(PENDING_FILE)
    if not pending or time.time() - pending.get("created", 0) > 1800:
        raise UserError("Die Anmeldung ist abgelaufen – bitte erneut „Mit Tibber verbinden“ starten.")
    answer = answer.strip()
    q = urllib.parse.parse_qs(urllib.parse.urlparse(answer).query) if "?" in answer else {"code": [answer]}
    if "error" in q:
        raise UserError(f"Tibber meldet: {q['error'][0]} {q.get('error_description', [''])[0]}".strip())
    if "state" in q and q["state"][0] != pending["state"]:
        raise UserError("Die Adresse gehört nicht zur aktuellen Anmeldung – bitte erneut starten.")
    code = q.get("code", [""])[0]
    if not code:
        raise UserError("In der eingefügten Adresse wurde kein Code gefunden.")
    resp = http_request(cfg["token_url"], data={
        "grant_type": "authorization_code",
        "code": code,
        "redirect_uri": cfg["redirect_uri"],
        "client_id": cfg["client_id"],
        "client_secret": cfg["client_secret"],
        "code_verifier": pending["verifier"],
    }, timeout=cfg["http_timeout"])
    tokens = store_token_response(cfg, resp)
    try:
        os.remove(PENDING_FILE)
    except OSError:
        pass
    return tokens


def run_auth_console(cfg):
    url = auth_start(cfg)
    print("\n1. Diese Adresse im Browser öffnen und mit dem Tibber-Konto anmelden:\n")
    print(url)
    print("\n2. Nach der Zustimmung leitet Tibber auf", cfg["redirect_uri"], "weiter.")
    print("   Der Browser zeigt dann eine Fehlerseite – das ist normal.")
    print("   Die komplette Adresse aus der Adresszeile kopieren und hier einfügen.\n")
    tokens = auth_finish(cfg, input("Weitergeleitete Adresse: "))
    print(f"\nAnmeldung erfolgreich. Gewährte Scopes: {tokens['scope'] or '(nicht angegeben)'}")


def refresh_tokens(cfg, tokens):
    debug("Access-Token wird erneuert")
    resp = http_request(cfg["token_url"], data={
        "grant_type": "refresh_token",
        "refresh_token": tokens["refresh_token"],
        "client_id": cfg["client_id"],
        "client_secret": cfg["client_secret"],
    }, timeout=cfg["http_timeout"])
    return store_token_response(cfg, resp, previous=tokens)


class Api:
    def __init__(self, cfg):
        require_client(cfg)
        self.cfg = cfg
        self.tokens = load_tokens(cfg)

    def _token(self, force=False):
        if force or self.tokens.get("expires_at", 0) - 120 < time.time():
            try:
                self.tokens = refresh_tokens(self.cfg, self.tokens)
            except ApiError as e:
                if e.status in (400, 401):
                    raise UserError("Tibber hat die Anmeldung abgelehnt – bitte im Plugin erneut "
                                    f"„Mit Tibber verbinden“ ausführen. ({e})")
                raise
        return self.tokens["access_token"]

    def get(self, path):
        url = self.cfg["api_base"].rstrip("/") + path
        for attempt in (0, 1):
            try:
                return http_request(url, headers={"Authorization": "Bearer " + self._token(force=attempt == 1)},
                                    timeout=self.cfg["http_timeout"])
            except ApiError as e:
                if e.status == 401 and attempt == 0:
                    continue
                raise

    def collect(self):
        """Alle Homes, Geräte und Gerätedetails. Fahrzeuge erscheinen in jedem Home -> deduplizieren."""
        q = urllib.parse.quote
        homes = self.get("/homes").get("homes", [])
        devices = {}
        for home in homes:
            try:
                listing = self.get(f"/homes/{q(home['id'], safe='')}/devices").get("devices", [])
            except ApiError as e:
                log(f"WARN Geräte für Home {home.get('id')} nicht abrufbar: {e}")
                continue
            for d in listing:
                if not isinstance(d, dict) or d.get("id") in devices:
                    continue
                try:
                    detail = self.get(f"/homes/{q(home['id'], safe='')}/devices/{q(d['id'], safe='')}")
                except ApiError as e:
                    log(f"WARN Gerät {d.get('id')} nicht abrufbar: {e}")
                    detail = {}
                devices[d["id"]] = {**d, **detail, "_homeId": home["id"]}
        return homes, list(devices.values())


# --------------------------------------------------------------------------- Mapping

def slug(text, fallback="x"):
    text = str(text or "")
    for a, b in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("Ä", "Ae"), ("Ö", "Oe"), ("Ü", "Ue"), ("ß", "ss")):
        text = text.replace(a, b)
    s = re.sub(r"[^A-Za-z0-9]+", "_", text).strip("_")
    return s or fallback


def scalar(v):
    """In einen MQTT-Payload umwandeln (bool -> 1/0). None für nicht-skalare Werte."""
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, str):
        return v
    return None


def word_num(value, available=None):
    if not isinstance(value, str):
        return None
    w = value.replace("_", "").replace("-", "").replace(" ", "").lower()
    if w in TRUE_WORDS:
        return "1"
    if w in FALSE_WORDS:
        return "0"
    if isinstance(available, list) and value in available:
        return str(available.index(value))
    return None


def parse_iso(s):
    try:
        return dt.datetime.fromisoformat(str(s).replace("Z", "+00:00"))
    except ValueError:
        return None


def flatten(prefix, obj, out):
    """Beliebige verschachtelte Strukturen als Topics ablegen (für unbekannte Felder)."""
    if isinstance(obj, dict):
        for k, v in obj.items():
            if k.startswith("_"):
                continue
            flatten(f"{prefix}/{slug(k)}", v, out)
    elif isinstance(obj, list):
        if all(scalar(x) is not None for x in obj):
            out[prefix] = ",".join(scalar(x) for x in obj)
        else:
            for i, v in enumerate(obj):
                flatten(f"{prefix}/{i}", v, out)
    else:
        s = scalar(obj)
        if s is not None:
            out[prefix] = s


def build_topics(cfg, homes, devices):
    p = cfg["topic_prefix"].rstrip("/")
    out = {}
    now = dt.datetime.now(dt.timezone.utc)

    used = set()
    home_slugs = {}
    for h in homes:
        s = slug(h.get("name") or h.get("id"), "home")
        if s in used:
            s = f"{s}_{slug(h['id'])[:8]}"
        used.add(s)
        home_slugs[h["id"]] = s
        flatten(f"{p}/home/{s}", h, out)

    used = set()
    for d in devices:
        info = d.get("info") or {}
        s = slug(info.get("name") or " ".join(filter(None, [info.get("brand"), info.get("model")])) or d["id"],
                 "device")
        if s in used:
            s = f"{s}_{slug(d['id'])[:8]}"
        used.add(s)
        base = f"{p}/device/{s}"

        out[f"{base}/id"] = d["id"]
        if len(homes) > 1 and d.get("_homeId") in home_slugs:
            out[f"{base}/home"] = home_slugs[d["_homeId"]]
        for k, v in info.items():
            if scalar(v) is not None:
                out[f"{base}/{slug(k)}"] = scalar(v)

        status = d.get("status") or {}
        for k, v in status.items():
            if scalar(v) is not None:
                out[f"{base}/{slug(k)}"] = scalar(v)
        ls = parse_iso(status.get("lastSeen")) if status.get("lastSeen") else None
        if ls:
            if ls.tzinfo is None:
                ls = ls.replace(tzinfo=dt.timezone.utc)
            out[f"{base}/lastSeen_ageMin"] = str(round((now - ls).total_seconds() / 60, 1))

        for c in d.get("capabilities") or []:
            if not isinstance(c, dict) or not c.get("id"):
                continue
            t = f"{base}/{slug(c['id'])}"
            val, unit = c.get("value"), c.get("unit")
            if scalar(val) is not None:
                out[t] = scalar(val)
            elif val is not None:
                flatten(t, val, out)
            n = word_num(val, c.get("availableValues"))
            if n is not None:
                out[f"{t}_num"] = n
            if isinstance(val, (int, float)) and not isinstance(val, bool):
                if unit == "m":
                    out[f"{t}_km"] = str(round(val / 1000, 1))
                elif unit in ("mi", "mile", "miles"):
                    out[f"{t}_km"] = str(round(val * 1.609344, 1))
            if cfg["publish_units"] and unit:
                out[f"{t}_unit"] = str(unit)

        if cfg["publish_attributes"]:
            for a in d.get("attributes") or []:
                if not isinstance(a, dict) or not a.get("id"):
                    continue
                t = f"{base}/attr/{slug(a['id'])}"
                if "value" in a:
                    v = a["value"]
                    if scalar(v) is not None:
                        out[t] = scalar(v)
                    elif v is not None:
                        flatten(t, v, out)
                    n = word_num(v, a.get("availableValues"))
                    if n is not None:
                        out[f"{t}_num"] = n
                for k, v in a.items():
                    if k in ("id", "value", "description", "availableValues") or k in SKIP_ATTR_FIELDS:
                        continue
                    if scalar(v) is None:
                        continue
                    # Attribute ohne "value" (z. B. Konnektivität): status ist der Hauptwert
                    kt = t if (k == "status" and "value" not in a) else f"{t}_{slug(k)}"
                    out[kt] = scalar(v)
                    if k == "status" and word_num(v) is not None:
                        out[f"{kt}_num"] = word_num(v)
    return out


# --------------------------------------------------------------------------- MQTT (minimal, MQTT 3.1.1)

def _enc_len(n):
    b = bytearray()
    while True:
        byte = n % 128
        n //= 128
        if n:
            byte |= 0x80
        b.append(byte)
        if not n:
            return bytes(b)


def _enc_str(s):
    raw = s.encode("utf-8")
    return struct.pack("!H", len(raw)) + raw


class Mqtt:
    def __init__(self, host, port, user="", password="", client_id=None, timeout=10):
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.pid = 0
        self.pending = 0
        flags = 0x02  # clean session
        payload = _enc_str(client_id or f"tibber-data-{secrets.token_hex(4)}")
        if user:
            flags |= 0x80
            payload += _enc_str(user)
            if password:
                flags |= 0x40
                payload += _enc_str(password)
        var = _enc_str("MQTT") + bytes([4, flags]) + struct.pack("!H", 60)
        self._send(0x10, var + payload)
        resp = self._recv_exact(4)
        if resp[0] != 0x20 or resp[3] != 0:
            codes = {1: "Protokoll", 2: "Client-ID", 3: "Server nicht verfügbar",
                     4: "Benutzer/Passwort falsch", 5: "nicht autorisiert"}
            raise ConnectionError(f"MQTT-Verbindung abgelehnt: {codes.get(resp[3], resp[3])}")

    def _recv_exact(self, n):
        buf = b""
        while len(buf) < n:
            chunk = self.sock.recv(n - len(buf))
            if not chunk:
                raise ConnectionError("MQTT-Broker hat die Verbindung geschlossen")
            buf += chunk
        return buf

    def _send(self, header, body):
        self.sock.sendall(bytes([header]) + _enc_len(len(body)) + body)

    def publish(self, topic, payload, retain=False):
        """QoS 1: der Broker bestätigt jede Nachricht (PUBACK), siehe wait_acks()."""
        self.pid = self.pid % 65535 + 1
        self.pending += 1
        self._send(0x32 | (0x01 if retain else 0),
                   _enc_str(topic) + struct.pack("!H", self.pid) + payload.encode("utf-8"))

    def wait_acks(self):
        while self.pending > 0:
            hdr = self._recv_exact(2)
            length, mult, b = hdr[1] & 0x7F, 128, hdr[1]
            while b & 0x80:
                b = self._recv_exact(1)[0]
                length += (b & 0x7F) * mult
                mult *= 128
            if length:
                self._recv_exact(length)
            if hdr[0] >> 4 == 4:  # PUBACK
                self.pending -= 1

    def close(self):
        try:
            self.wait_acks()
            time.sleep(0.2)
            self._send(0xE0, b"")
            self.sock.close()
        except OSError:
            pass


# --------------------------------------------------------------------------- Main

def publish_all(cfg, topics, dry_run=False):
    p = cfg["topic_prefix"].rstrip("/")
    if dry_run:
        for t in sorted(topics):
            print(f"{t} = {topics[t]}")
        return
    s = mqtt_settings(cfg)
    debug(f"MQTT {s['host']}:{s['port']} user={s['user'] or '-'}")
    m = Mqtt(s["host"], s["port"], s["user"], s["pass"])
    try:
        for t, v in topics.items():
            m.publish(t, v, retain=cfg["retain"])
        m.publish(f"{p}/status/ok", "1", retain=cfg["retain"])
        m.publish(f"{p}/status/error", "", retain=cfg["retain"])
        m.publish(f"{p}/status/lastRun", dt.datetime.now().astimezone().isoformat(timespec="seconds"),
                  retain=cfg["retain"])
        m.publish(f"{p}/status/lastRun_epoch", str(int(time.time())), retain=cfg["retain"])
    finally:
        m.close()


def publish_error(cfg, msg):
    p = cfg["topic_prefix"].rstrip("/")
    try:
        s = mqtt_settings(cfg)
        m = Mqtt(s["host"], s["port"], s["user"], s["pass"])
        m.publish(f"{p}/status/ok", "0", retain=cfg["retain"])
        m.publish(f"{p}/status/error", msg[:250], retain=cfg["retain"])
        m.close()
    except (OSError, ConnectionError):
        pass


def write_status(**kw):
    st = read_json(STATUS_FILE, {}) or {}
    st.update(kw)
    write_json(STATUS_FILE, st)


def run_once(cfg, dry_run=False):
    api = Api(cfg)
    homes, devices = api.collect()
    topics = build_topics(cfg, homes, devices)
    publish_all(cfg, topics, dry_run)
    msg = (f"{len(homes)} Home(s), {len(devices)} Gerät(e), {len(topics)} Werte "
           f"{'angezeigt' if dry_run else 'an MQTT gesendet'}")
    log(msg)
    if not dry_run:
        write_status(ok=True, error="", lastRun=int(time.time()), message=msg, homes=len(homes),
                     devices=[(d.get("info") or {}).get("name") or d.get("id") for d in devices],
                     values=len(topics), scope=api.tokens.get("scope", ""))


def rotate_log():
    try:
        if os.path.getsize(LOG_FILE) > 1_000_000:
            with open(LOG_FILE, "rb") as f:
                f.seek(-200_000, os.SEEK_END)
                tail = f.read()
            with open(LOG_FILE, "wb") as f:
                f.write(tail[tail.find(b"\n") + 1:])
    except OSError:
        pass


def cron_due(cfg):
    if not cfg.get("enabled", True):
        return False
    if not os.path.exists(cfg["token_file"]):
        return False  # noch nicht verbunden: nicht jede Minute einen Fehler loggen
    st = read_json(STATUS_FILE, {}) or {}
    last = st.get("lastAttempt", 0)
    interval = max(1, int(cfg.get("interval_min") or 5)) * 60
    return time.time() - last >= interval - 20  # 20 s Toleranz für Cron-Ungenauigkeit


def main():
    global DEBUG, LOG_TO_FILE
    ap = argparse.ArgumentParser(description="Tibber Data API -> MQTT für LoxBerry")
    ap.add_argument("--config", default=os.path.join(CONFIG_DIR, "config.json"))
    ap.add_argument("--cron", action="store_true", help="nur ausführen, wenn aktiviert und Intervall abgelaufen")
    ap.add_argument("--auth", action="store_true", help="Anmeldung bei Tibber in der Konsole")
    ap.add_argument("--auth-start", action="store_true", help="Anmelde-Adresse ausgeben")
    ap.add_argument("--auth-finish", metavar="URL", help="Anmeldung mit weitergeleiteter Adresse abschließen")
    ap.add_argument("--dump", action="store_true", help="Rohdaten der API als JSON ausgeben")
    ap.add_argument("--dry-run", action="store_true", help="Topics anzeigen, nicht senden")
    ap.add_argument("--debug", action="store_true")
    args = ap.parse_args()
    DEBUG = args.debug
    # Cron leitet stdout bereits ins Log um; Konsole/Weboberfläche schreiben selbst hinein
    LOG_TO_FILE = not args.cron and not args.dump and not args.dry_run and os.path.isdir(LOG_DIR)
    cfg = load_config(args.config)

    try:
        if args.auth:
            run_auth_console(cfg)
        elif args.auth_start:
            print(auth_start(cfg))
        elif args.auth_finish:
            tokens = auth_finish(cfg, args.auth_finish)
            write_status(ok=None, error="", connectedAt=int(time.time()), scope=tokens.get("scope", ""),
                         lastAttempt=0)
            print("Verbunden. Gewährte Scopes: " + (tokens.get("scope") or "(nicht angegeben)"))
        elif args.dump:
            homes, devices = Api(cfg).collect()
            print(json.dumps({"homes": homes, "devices": devices}, indent=2, ensure_ascii=False))
        else:
            if args.cron:
                rotate_log()
                lock = open(LOCK_FILE, "w")
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except OSError:
                    return  # vorheriger Lauf läuft noch
                if not cron_due(cfg):
                    return
            if not args.dry_run:
                write_status(lastAttempt=int(time.time()))
            try:
                run_once(cfg, args.dry_run)
            except (ApiError, UserError, OSError, ConnectionError, ValueError) as e:
                if not args.dry_run:
                    write_status(ok=False, error=str(e), lastErrorAt=int(time.time()))
                    publish_error(cfg, str(e))
                raise
    except (ApiError, UserError, OSError, ConnectionError, ValueError) as e:
        log(f"FEHLER {e}")
        sys.exit(1)
    except KeyboardInterrupt:
        sys.exit(130)


if __name__ == "__main__":
    main()
