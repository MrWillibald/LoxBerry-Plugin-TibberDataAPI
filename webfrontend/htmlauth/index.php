<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$cfgfile    = LBPCONFIGDIR . "/config.json";
$tokenfile  = LBPCONFIGDIR . "/tokens.json";
$subsfile   = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";
$statusfile = LBPDATADIR . "/status.json";
$pendfile   = LBPDATADIR . "/auth_pending.json";
$logfile    = LBPLOGDIR . "/tibberdata.log";
$script     = LBPBINDIR . "/tibber_data_mqtt.py";

$defaults = [
	"enabled" => true, "interval_min" => 5, "client_id" => "", "client_secret" => "",
	"redirect_uri" => "http://localhost:8080/callback", "topic_prefix" => "tibberdata",
	"retain" => true, "publish_attributes" => true, "publish_units" => false,
];

// Zeitzone des Systems verwenden, falls PHP keine kennt
if (!ini_get("date.timezone") && is_readable("/etc/timezone")) {
	@date_default_timezone_set(trim(file_get_contents("/etc/timezone")));
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function read_json_file($f) {
	if (!is_readable($f)) return null;
	$j = json_decode(file_get_contents($f), true);
	return is_array($j) ? $j : null;
}

function write_config($f, $pcfg) {
	file_put_contents($f, json_encode($pcfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
	@chmod($f, 0600);
}

function run_script($script, $args) {
	$cmd = "/usr/bin/python3 " . escapeshellarg($script);
	foreach ($args as $a) $cmd .= " " . escapeshellarg($a);
	exec($cmd . " 2>&1", $out, $rc);
	return [$rc, implode("\n", $out)];
}

function fmt_time($ts) {
	return $ts ? date("d.m.Y H:i:s", (int)$ts) : "–";
}

$pcfg = array_merge($defaults, read_json_file($cfgfile) ?? []);
$msg = null;      // [typ, text]  typ: ok | err | info
$output = null;   // Ausgabe von Aktionen
$valuetable = null;
$authurl = null;

$action = $_POST["action"] ?? "";

if ($action === "save") {
	$prefix = trim($_POST["topic_prefix"] ?? "", "/ ");
	$interval = (int)($_POST["interval_min"] ?? 5);
	if (!preg_match('/^[A-Za-z0-9_\-\/]+$/', $prefix)) {
		$msg = ["err", "Das Topic-Präfix darf nur Buchstaben, Ziffern, _ - und / enthalten."];
	} elseif ($interval < 1 || $interval > 1440) {
		$msg = ["err", "Das Intervall muss zwischen 1 und 1440 Minuten liegen."];
	} else {
		$oldprefix = $pcfg["topic_prefix"];
		$pcfg["enabled"] = isset($_POST["enabled"]);
		$pcfg["interval_min"] = $interval;
		$pcfg["client_id"] = trim($_POST["client_id"] ?? "");
		if (trim($_POST["client_secret"] ?? "") !== "") {
			$pcfg["client_secret"] = trim($_POST["client_secret"]);
		}
		$pcfg["topic_prefix"] = $prefix;
		$pcfg["retain"] = isset($_POST["retain"]);
		$pcfg["publish_attributes"] = isset($_POST["publish_attributes"]);
		$pcfg["publish_units"] = isset($_POST["publish_units"]);
		write_config($cfgfile, $pcfg);
		file_put_contents($subsfile, $prefix . "/#\n");
		$msg = ["ok", "Einstellungen gespeichert."];
		if ($oldprefix !== $prefix) {
			$msg[1] .= " Das MQTT Gateway abonniert jetzt „" . $prefix . "/#“.";
		}
	}
} elseif ($action === "auth_start") {
	[$rc, $out] = run_script($script, ["--auth-start"]);
	if ($rc === 0) {
		$authurl = trim($out);
	} else {
		$msg = ["err", $out];
	}
} elseif ($action === "auth_finish") {
	[$rc, $out] = run_script($script, ["--auth-finish", trim($_POST["redirect_url"] ?? "")]);
	$msg = [$rc === 0 ? "ok" : "err", $out];
	if ($rc === 0) {
		[$rc2, $out2] = run_script($script, []);
		$msg[1] .= "\n" . $out2;
	}
} elseif ($action === "disconnect") {
	@unlink($tokenfile);
	@unlink($pendfile);
	$msg = ["info", "Verbindung zu Tibber entfernt."];
} elseif ($action === "run_now") {
	[$rc, $out] = run_script($script, []);
	$msg = [$rc === 0 ? "ok" : "err", $out];
} elseif ($action === "values") {
	[$rc, $out] = run_script($script, ["--dry-run"]);
	if ($rc === 0) {
		$valuetable = [];
		foreach (explode("\n", $out) as $line) {
			$p = strpos($line, " = ");
			if ($p !== false && strpos($line, "/") !== false) {
				$t = substr($line, 0, $p);
				$valuetable[] = [$t, str_replace("/", "_", $t), substr($line, $p + 3)];
			}
		}
	} else {
		$msg = ["err", $out];
	}
} elseif ($action === "dump") {
	[$rc, $out] = run_script($script, ["--dump"]);
	if ($rc === 0) $output = $out; else $msg = ["err", $out];
}

$status = read_json_file($statusfile) ?? [];
$connected = is_readable($tokenfile);
$pending = read_json_file($pendfile);
$pending_active = $pending && (time() - ($pending["created"] ?? 0) < 1800);
$has_client = $pcfg["client_id"] !== "" && $pcfg["client_secret"] !== "";

LBWeb::lbheader("Tibber Data API", "", "");
?>
<style>
	.tdbox { border: 1px solid #ccc; border-radius: 6px; padding: 10px 14px; margin: 14px 0; background: #fff; }
	.tdbox h2 { margin-top: 4px; font-size: 1.15em; }
	.tdmsg { white-space: pre-wrap; padding: 8px 12px; border-radius: 5px; margin: 10px 0; }
	.tdmsg.ok   { background: #e5f6e8; border: 1px solid #6cbf7b; }
	.tdmsg.err  { background: #fbe9e9; border: 1px solid #d77; }
	.tdmsg.info { background: #eef3fa; border: 1px solid #8aa8d6; }
	.tdstatus td { padding: 3px 12px 3px 0; vertical-align: top; }
	.tdgood { color: #2a8a3e; font-weight: bold; }
	.tdbad  { color: #c0392b; font-weight: bold; }
	.tdwarn { color: #b37400; font-weight: bold; }
	.tdpre  { white-space: pre-wrap; word-break: break-all; font-family: monospace; font-size: 12px;
	          background: #f6f6f6; border: 1px solid #ddd; padding: 8px; max-height: 420px; overflow: auto; }
	table.tdvals { border-collapse: collapse; width: 100%; font-size: 13px; }
	table.tdvals th, table.tdvals td { border-bottom: 1px solid #e3e3e3; padding: 4px 6px; text-align: left; word-break: break-all; }
	table.tdvals th { background: #f0f0f0; }
	.tdhint { color: #666; font-size: 0.9em; }
	.tdinline form { display: inline-block; margin-right: 6px; }
</style>

<?php if ($msg): ?>
	<div class="tdmsg <?= h($msg[0]) ?>"><?= h($msg[1]) ?></div>
<?php endif; ?>

<!-- ======================================================= Status -->
<div class="tdbox">
	<h2>Status</h2>
	<table class="tdstatus">
		<tr><td>Tibber-Verbindung</td><td>
			<?php if ($connected): ?><span class="tdgood">verbunden</span>
			<?php elseif (!$has_client): ?><span class="tdwarn">Client-ID/Secret fehlen</span>
			<?php else: ?><span class="tdwarn">noch nicht verbunden</span><?php endif; ?>
		</td></tr>
		<tr><td>Automatische Abfrage</td><td><?= $pcfg["enabled"] ? "alle " . (int)$pcfg["interval_min"] . " Minuten" : "<span class='tdwarn'>ausgeschaltet</span>" ?></td></tr>
		<tr><td>Letzte erfolgreiche Abfrage</td><td><?= h(fmt_time($status["lastRun"] ?? 0)) ?><?= isset($status["message"]) ? " – " . h($status["message"]) : "" ?></td></tr>
		<?php if (($status["ok"] ?? null) === false): ?>
		<tr><td>Letzter Fehler</td><td><span class="tdbad"><?= h(fmt_time($status["lastErrorAt"] ?? 0)) ?></span><br><?= h($status["error"] ?? "") ?></td></tr>
		<?php endif; ?>
		<?php if (!empty($status["devices"])): ?>
		<tr><td>Geräte</td><td><?= h(implode(", ", $status["devices"])) ?></td></tr>
		<?php endif; ?>
		<tr><td>MQTT-Topics</td><td><code><?= h($pcfg["topic_prefix"]) ?>/#</code> <span class="tdhint">(im MQTT Gateway automatisch abonniert)</span></td></tr>
	</table>
	<?php if ($connected): ?>
	<div class="tdinline" style="margin-top:8px">
		<form method="post" data-ajax="false"><input type="hidden" name="action" value="run_now">
			<button type="submit" data-inline="true" data-mini="true">Jetzt abfragen</button></form>
		<form method="post" data-ajax="false"><input type="hidden" name="action" value="values">
			<button type="submit" data-inline="true" data-mini="true">Werte &amp; Loxone-Namen anzeigen</button></form>
		<form method="post" data-ajax="false"><input type="hidden" name="action" value="dump">
			<button type="submit" data-inline="true" data-mini="true">Rohdaten der API</button></form>
	</div>
	<?php endif; ?>
</div>

<?php if ($valuetable !== null): ?>
<div class="tdbox">
	<h2>Aktuelle Werte (<?= count($valuetable) ?>)</h2>
	<p class="tdhint">In der Loxone Config einen <b>Virtuellen Eingang</b> (Zahlen) bzw. <b>Virtuellen Texteingang</b> (Texte) mit dem Namen aus der Spalte „Loxone-Name“ anlegen.</p>
	<table class="tdvals">
		<tr><th>MQTT-Topic</th><th>Loxone-Name</th><th>Wert</th></tr>
		<?php foreach ($valuetable as $r): ?>
		<tr><td><?= h($r[0]) ?></td><td><?= h($r[1]) ?></td><td><?= h($r[2]) ?></td></tr>
		<?php endforeach; ?>
	</table>
</div>
<?php endif; ?>

<?php if ($output !== null): ?>
<div class="tdbox">
	<h2>Rohdaten der Tibber Data API</h2>
	<div class="tdpre"><?= h($output) ?></div>
</div>
<?php endif; ?>

<!-- ======================================================= Verbindung -->
<div class="tdbox">
	<h2>Verbindung mit Tibber</h2>
	<?php if (!$has_client): ?>
		<p>Zuerst unten in den Einstellungen die <b>Client-ID</b> und das <b>Client-Secret</b> eintragen und speichern.</p>
	<?php elseif ($authurl || $pending_active): ?>
		<?php if ($authurl): ?>
		<p><b>Schritt 1:</b> Anmeldeseite öffnen, mit dem Tibber-Konto anmelden und zustimmen:</p>
		<p><a href="<?= h($authurl) ?>" target="_blank" rel="noopener" class="ui-btn ui-btn-inline ui-mini ui-corner-all">Tibber-Anmeldung öffnen</a></p>
		<?php endif; ?>
		<p><b>Schritt <?= $authurl ? "2" : "" ?>:</b> Danach landet der Browser auf <code><?= h($pcfg["redirect_uri"]) ?>…</code> und zeigt eine
			<b>Fehlerseite – das ist normal</b>. Die komplette Adresse aus der Adresszeile kopieren und hier einfügen:</p>
		<form method="post" data-ajax="false">
			<input type="hidden" name="action" value="auth_finish">
			<input type="text" name="redirect_url" placeholder="http://localhost:8080/callback?code=...&amp;state=..." required>
			<button type="submit" data-inline="true">Anmeldung abschließen</button>
		</form>
		<?php if (!$authurl): ?>
		<form method="post" data-ajax="false"><input type="hidden" name="action" value="auth_start">
			<button type="submit" data-inline="true" data-mini="true">Neue Anmelde-Adresse erzeugen</button></form>
		<?php endif; ?>
	<?php else: ?>
		<?php if ($connected): ?>
			<p>Das Plugin ist mit Tibber verbunden.
			<?php if (!empty($status["scope"])): ?><br><span class="tdhint">Freigaben: <?= h($status["scope"]) ?></span><?php endif; ?></p>
		<?php endif; ?>
		<div class="tdinline">
			<form method="post" data-ajax="false"><input type="hidden" name="action" value="auth_start">
				<button type="submit" data-inline="true"><?= $connected ? "Neu verbinden" : "Mit Tibber verbinden" ?></button></form>
			<?php if ($connected): ?>
			<form method="post" data-ajax="false" onsubmit="return confirm('Verbindung zu Tibber wirklich entfernen?');">
				<input type="hidden" name="action" value="disconnect">
				<button type="submit" data-inline="true" data-mini="true">Verbindung entfernen</button></form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

<!-- ======================================================= Einstellungen -->
<div class="tdbox">
	<h2>Einstellungen</h2>
	<form method="post" data-ajax="false">
		<input type="hidden" name="action" value="save">

		<label><input type="checkbox" name="enabled" <?= $pcfg["enabled"] ? "checked" : "" ?>> Automatische Abfrage aktiv</label>

		<label for="interval_min">Abfrageintervall (Minuten)</label>
		<input type="number" id="interval_min" name="interval_min" min="1" max="1440" value="<?= (int)$pcfg["interval_min"] ?>">

		<label for="client_id">Client-ID</label>
		<input type="text" id="client_id" name="client_id" value="<?= h($pcfg["client_id"]) ?>" autocomplete="off">

		<label for="client_secret">Client-Secret</label>
		<input type="password" id="client_secret" name="client_secret" value="" autocomplete="new-password"
			placeholder="<?= $pcfg["client_secret"] !== "" ? "gespeichert – leer lassen, um es beizubehalten" : "" ?>">

		<label for="topic_prefix">MQTT-Topic-Präfix</label>
		<input type="text" id="topic_prefix" name="topic_prefix" value="<?= h($pcfg["topic_prefix"]) ?>">

		<label><input type="checkbox" name="retain" <?= $pcfg["retain"] ? "checked" : "" ?>> Werte beim Broker speichern (retain)</label>
		<label><input type="checkbox" name="publish_attributes" <?= $pcfg["publish_attributes"] ? "checked" : "" ?>> Geräte-Attribute mitsenden (Firmware, Konnektivität …)</label>
		<label><input type="checkbox" name="publish_units" <?= $pcfg["publish_units"] ? "checked" : "" ?>> Einheiten als eigene Topics senden (…_unit)</label>

		<button type="submit" data-inline="true" data-theme="b">Speichern</button>
	</form>

	<details style="margin-top:10px">
		<summary><b>So legst du den OAuth-Client bei Tibber an</b></summary>
		<ol>
			<li><a href="https://data-api.tibber.com/clients/manage" target="_blank" rel="noopener">data-api.tibber.com/clients/manage</a> öffnen und mit dem Tibber-Konto anmelden, in dem die Geräte hinterlegt sind.</li>
			<li>Neuen Client anlegen. <b>Redirect URI:</b> <code><?= h($pcfg["redirect_uri"]) ?></code></li>
			<li>Bei den Scopes alle Gerätekategorien (Vehicles, Chargers, Thermostats, Energy Systems, Inverters) sowie Homes und User anhaken.</li>
			<li>Client-ID und Client-Secret hier eintragen und speichern. Das Secret zeigt Tibber nur einmal an.</li>
			<li>Danach oben auf „Mit Tibber verbinden“ klicken.</li>
		</ol>
	</details>
</div>

<!-- ======================================================= Log -->
<div class="tdbox">
	<h2>Log</h2>
	<?php
	$lines = is_readable($logfile) ? array_slice(file($logfile, FILE_IGNORE_NEW_LINES), -60) : [];
	?>
	<div class="tdpre"><?= $lines ? h(implode("\n", array_reverse($lines))) : "Noch keine Einträge." ?></div>
	<p class="tdhint">Neueste Einträge oben. Vollständige Datei: <?= h($logfile) ?></p>
</div>

<?php
LBWeb::lbfooter();
?>
