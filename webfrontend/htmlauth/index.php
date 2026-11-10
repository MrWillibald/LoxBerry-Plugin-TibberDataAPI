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

// "vor 3 Min." mit exakter Zeit als Tooltip
function fmt_ago($ts) {
	if (!$ts) return "–";
	$d = time() - (int)$ts;
	if ($d < 60)         $t = "gerade eben";
	elseif ($d < 3600)   $t = "vor " . intdiv($d, 60) . " Min.";
	elseif ($d < 86400)  $t = "vor " . intdiv($d, 3600) . " Std.";
	else { $n = intdiv($d, 86400); $t = "vor $n " . ($n === 1 ? "Tag" : "Tagen"); }
	return '<span title="' . h(fmt_time($ts)) . '">' . h($t) . '</span>';
}

$tabs = ["overview" => "Übersicht", "settings" => "Einstellungen", "log" => "Log"];
$tab = $_GET["tab"] ?? "overview";
if (!isset($tabs[$tab])) $tab = "overview";

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
$last_failed = ($status["ok"] ?? null) === false;

// Reiterleiste von LoxBerry
$navbar = [];
$i = 1;
foreach ($tabs as $key => $name) {
	$navbar[$i] = ["Name" => $name, "URL" => "index.php?tab=" . $key];
	if ($key === $tab) $navbar[$i]["active"] = true;
	$i++;
}

LBWeb::lbheader("Tibber Data API", "", "");
?>
<style>
	.tdbox { border: 1px solid #d6d6d6; border-radius: 8px; padding: 12px 16px; margin: 14px 0; background: #fff; }
	.tdbox h2 { margin: 2px 0 10px; font-size: 1.15em; }
	.tdmsg { white-space: pre-wrap; padding: 8px 12px; border-radius: 6px; margin: 10px 0; }
	.tdmsg.ok   { background: #e5f6e8; border: 1px solid #6cbf7b; }
	.tdmsg.err  { background: #fbe9e9; border: 1px solid #d77; }
	.tdmsg.info { background: #eef3fa; border: 1px solid #8aa8d6; }
	.tdhint { color: #666; font-size: 0.9em; }
	.tdinline form { display: inline-block; margin-right: 6px; }
	.tdpre  { white-space: pre-wrap; word-break: break-all; font-family: monospace; font-size: 12px;
	          background: #f6f6f6; border: 1px solid #ddd; padding: 8px; max-height: 420px; overflow: auto; }

	/* Statuskacheln */
	.tdtiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin: 14px 0; }
	.tdtile { border: 1px solid #d6d6d6; border-left: 5px solid #aaa; border-radius: 8px; padding: 10px 12px; background: #fff; }
	.tdtile .lbl { font-size: 0.8em; color: #666; text-transform: uppercase; letter-spacing: .03em; }
	.tdtile .val { font-size: 1.25em; font-weight: bold; margin: 3px 0 2px; }
	.tdtile .sub { font-size: 0.85em; color: #555; word-break: break-word; }
	.tdtile.good { border-left-color: #2a8a3e; } .tdtile.good .val { color: #2a8a3e; }
	.tdtile.warn { border-left-color: #d08a00; } .tdtile.warn .val { color: #b37400; }
	.tdtile.bad  { border-left-color: #c0392b; } .tdtile.bad  .val { color: #c0392b; }

	/* Anmeldeschritte */
	.tdsteps { list-style: none; padding: 0; margin: 0; counter-reset: st; }
	.tdsteps > li { position: relative; padding: 2px 0 14px 42px; counter-increment: st; color: #888; }
	.tdsteps > li::before { content: counter(st); position: absolute; left: 0; top: 0; width: 28px; height: 28px;
		border-radius: 50%; background: #ddd; color: #fff; font-weight: bold; text-align: center; line-height: 28px; }
	.tdsteps > li:not(:last-child)::after { content: ""; position: absolute; left: 13px; top: 32px; bottom: 4px; width: 2px; background: #e3e3e3; }
	.tdsteps > li.done { color: #444; }
	.tdsteps > li.done::before { content: "✓"; background: #2a8a3e; }
	.tdsteps > li.current { color: #222; }
	.tdsteps > li.current::before { background: #3a7bd5; }
	.tdsteps .title { font-weight: bold; line-height: 28px; }

	/* Wertetabelle */
	table.tdvals { border-collapse: collapse; width: 100%; font-size: 13px; }
	table.tdvals th, table.tdvals td { border-bottom: 1px solid #e3e3e3; padding: 4px 6px; text-align: left; word-break: break-all; }
	table.tdvals th { background: #f0f0f0; }
	button.tdcopy { border: 1px solid #ccc; background: #f7f7f7; border-radius: 4px; padding: 1px 6px; margin-left: 6px;
		font-size: 11px; cursor: pointer; }
	button.tdcopy.done { background: #e5f6e8; border-color: #6cbf7b; }
	input.tdfilter { width: 100%; max-width: 360px; box-sizing: border-box; padding: 6px 8px; margin: 4px 0 8px;
		border: 1px solid #ccc; border-radius: 4px; }

	/* Log */
	.tdlog { font-family: monospace; font-size: 12px; background: #f6f6f6; border: 1px solid #ddd; max-height: 560px; overflow: auto; }
	.tdlog div { padding: 1px 8px; white-space: pre-wrap; word-break: break-all; }
	.tdlog .err  { background: #fbe9e9; color: #a02020; }
	.tdlog .warn { background: #fff4dc; color: #8a5a00; }
	.tdlog .dbg  { color: #888; }
	.tdlog.problems div:not(.err):not(.warn) { display: none; }
</style>

<?php if ($msg): ?>
	<div class="tdmsg <?= h($msg[0]) ?>"><?= h($msg[1]) ?></div>
<?php endif; ?>

<?php if ($tab === "overview"): ?>
<!-- ======================================================= Übersicht -->
<div class="tdtiles">
	<?php if ($connected): ?>
		<div class="tdtile good"><div class="lbl">Tibber</div><div class="val">verbunden</div>
			<div class="sub"><?= !empty($status["connectedAt"]) ? "seit " . h(date("d.m.Y", (int)$status["connectedAt"])) : "&nbsp;" ?></div></div>
	<?php else: ?>
		<div class="tdtile warn"><div class="lbl">Tibber</div><div class="val">nicht verbunden</div>
			<div class="sub"><?= $has_client ? "Anmeldung ausstehend" : "Client-ID/Secret fehlen" ?></div></div>
	<?php endif; ?>

	<div class="tdtile <?= $pcfg["enabled"] ? "good" : "warn" ?>"><div class="lbl">Automatische Abfrage</div>
		<div class="val"><?= $pcfg["enabled"] ? "alle " . (int)$pcfg["interval_min"] . " Min." : "aus" ?></div>
		<div class="sub">MQTT: <code><?= h($pcfg["topic_prefix"]) ?>/#</code></div></div>

	<?php if ($last_failed): ?>
		<div class="tdtile bad"><div class="lbl">Letzte Abfrage</div><div class="val">Fehler</div>
			<div class="sub"><?= fmt_ago($status["lastErrorAt"] ?? 0) ?> – <?= h($status["error"] ?? "") ?></div></div>
	<?php else: ?>
		<div class="tdtile <?= !empty($status["lastRun"]) ? "good" : "" ?>"><div class="lbl">Letzte Abfrage</div>
			<div class="val"><?= fmt_ago($status["lastRun"] ?? 0) ?></div>
			<div class="sub"><?= isset($status["values"]) ? (int)$status["values"] . " Werte gesendet" : "noch keine" ?></div></div>
	<?php endif; ?>

	<div class="tdtile"><div class="lbl">Geräte</div>
		<div class="val"><?= !empty($status["devices"]) ? count($status["devices"]) : "–" ?></div>
		<div class="sub"><?= !empty($status["devices"]) ? h(implode(", ", $status["devices"])) : "&nbsp;" ?></div></div>
</div>

<?php if ($connected): ?>
<div class="tdinline">
	<form method="post" action="?tab=overview" data-ajax="false"><input type="hidden" name="action" value="run_now">
		<button type="submit" data-inline="true" data-mini="true" data-icon="refresh">Jetzt abfragen</button></form>
	<form method="post" action="?tab=overview" data-ajax="false"><input type="hidden" name="action" value="values">
		<button type="submit" data-inline="true" data-mini="true" data-icon="bars">Werte &amp; Loxone-Namen</button></form>
	<form method="post" action="?tab=overview" data-ajax="false"><input type="hidden" name="action" value="dump">
		<button type="submit" data-inline="true" data-mini="true" data-icon="info">Rohdaten der API</button></form>
</div>
<?php endif; ?>

<?php if ($valuetable !== null): ?>
<div class="tdbox">
	<h2>Aktuelle Werte (<?= count($valuetable) ?>)</h2>
	<p class="tdhint">In der Loxone Config einen <b>Virtuellen Eingang</b> (Zahlen) bzw. <b>Virtuellen Texteingang</b> (Texte)
		mit dem Namen aus der Spalte „Loxone-Name“ anlegen. „Kopieren“ legt den Namen in die Zwischenablage.</p>
	<input type="search" class="tdfilter" id="tdfilter" placeholder="Filtern, z. B. battery oder soc" data-role="none">
	<table class="tdvals" id="tdvals">
		<tr><th>MQTT-Topic</th><th>Loxone-Name</th><th>Wert</th></tr>
		<?php foreach ($valuetable as $r): ?>
		<tr><td><?= h($r[0]) ?></td>
			<td><span class="tdname"><?= h($r[1]) ?></span><button type="button" class="tdcopy" data-role="none">Kopieren</button></td>
			<td><?= h($r[2]) ?></td></tr>
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

<div class="tdbox">
	<h2>Verbindung mit Tibber</h2>
	<?php if ($connected && !$authurl && !$pending_active): ?>
		<details>
			<summary>Das Plugin ist mit Tibber verbunden. <span class="tdhint">Details und Optionen</span></summary>
			<?php if (!empty($status["scope"])): ?><p class="tdhint">Freigaben: <?= h($status["scope"]) ?></p><?php endif; ?>
			<div class="tdinline">
				<form method="post" action="?tab=overview" data-ajax="false"><input type="hidden" name="action" value="auth_start">
					<button type="submit" data-inline="true" data-mini="true">Neu verbinden</button></form>
				<form method="post" action="?tab=overview" data-ajax="false" onsubmit="return confirm('Verbindung zu Tibber wirklich entfernen?');">
					<input type="hidden" name="action" value="disconnect">
					<button type="submit" data-inline="true" data-mini="true">Verbindung entfernen</button></form>
			</div>
		</details>
	<?php else:
		$s2 = $authurl || $pending_active;  // Anmelde-Adresse wurde erzeugt
	?>
		<ol class="tdsteps">
			<li class="<?= $has_client ? "done" : "current" ?>">
				<div class="title">Client-ID und Client-Secret eintragen</div>
				<?php if (!$has_client): ?>
				<p>Im Reiter <a href="?tab=settings" data-ajax="false">Einstellungen</a> eintragen und speichern.
					Dort steht auch, wie du den Client bei Tibber anlegst.</p>
				<?php endif; ?>
			</li>
			<li class="<?= !$has_client ? "" : (($s2 && !$authurl) ? "done" : "current") ?>">
				<div class="title">Bei Tibber anmelden</div>
				<?php if ($has_client && $authurl): ?>
					<p>Anmeldeseite öffnen, mit dem Tibber-Konto anmelden und zustimmen:</p>
					<p><a href="<?= h($authurl) ?>" target="_blank" rel="noopener" class="ui-btn ui-btn-inline ui-mini ui-corner-all ui-btn-b">Tibber-Anmeldung öffnen</a></p>
				<?php elseif ($has_client && !$s2): ?>
					<form method="post" action="?tab=overview" data-ajax="false"><input type="hidden" name="action" value="auth_start">
						<button type="submit" data-inline="true" data-theme="b">Mit Tibber verbinden</button></form>
				<?php endif; ?>
			</li>
			<li class="<?= $s2 ? "current" : "" ?>">
				<div class="title">Adresse aus dem Browser einfügen</div>
				<?php if ($s2): ?>
					<p>Nach der Anmeldung landet der Browser auf <code><?= h($pcfg["redirect_uri"]) ?>…</code> und zeigt eine
						<b>Fehlerseite – das ist normal</b>. Die komplette Adresse aus der Adresszeile kopieren und hier einfügen:</p>
					<form method="post" action="?tab=overview" data-ajax="false">
						<input type="hidden" name="action" value="auth_finish">
						<input type="text" name="redirect_url" placeholder="http://localhost:8080/callback?code=...&amp;state=..." required>
						<button type="submit" data-inline="true" data-theme="b">Anmeldung abschließen</button>
					</form>
					<?php if (!$authurl): ?>
					<form method="post" action="?tab=overview" data-ajax="false"><input type="hidden" name="action" value="auth_start">
						<button type="submit" data-inline="true" data-mini="true">Neue Anmelde-Adresse erzeugen</button></form>
					<?php endif; ?>
				<?php endif; ?>
			</li>
		</ol>
	<?php endif; ?>
</div>

<?php elseif ($tab === "settings"): ?>
<!-- ======================================================= Einstellungen -->
<div class="tdbox">
	<form method="post" action="?tab=settings" data-ajax="false">
		<input type="hidden" name="action" value="save">

		<h2>Abfrage</h2>
		<label><input type="checkbox" name="enabled" <?= $pcfg["enabled"] ? "checked" : "" ?>> Automatische Abfrage aktiv</label>
		<label for="interval_min">Abfrageintervall (Minuten)</label>
		<input type="number" id="interval_min" name="interval_min" min="1" max="1440" value="<?= (int)$pcfg["interval_min"] ?>">

		<h2 style="margin-top:20px">Tibber-Zugang</h2>
		<label for="client_id">Client-ID</label>
		<input type="text" id="client_id" name="client_id" value="<?= h($pcfg["client_id"]) ?>" autocomplete="off">
		<label for="client_secret">Client-Secret</label>
		<input type="password" id="client_secret" name="client_secret" value="" autocomplete="new-password"
			placeholder="<?= $pcfg["client_secret"] !== "" ? "gespeichert – leer lassen, um es beizubehalten" : "" ?>">
		<details style="margin:6px 0 4px">
			<summary><b>So legst du den OAuth-Client bei Tibber an</b></summary>
			<ol>
				<li><a href="https://data-api.tibber.com/clients/manage" target="_blank" rel="noopener">data-api.tibber.com/clients/manage</a> öffnen und mit dem Tibber-Konto anmelden, in dem die Geräte hinterlegt sind.</li>
				<li>Neuen Client anlegen. <b>Redirect URI:</b> <code><?= h($pcfg["redirect_uri"]) ?></code></li>
				<li>Bei den Scopes alle Gerätekategorien (Vehicles, Chargers, Thermostats, Energy Systems, Inverters) sowie Homes und User anhaken.</li>
				<li>Client-ID und Client-Secret hier eintragen und speichern. Das Secret zeigt Tibber nur einmal an.</li>
				<li>Danach im Reiter <a href="?tab=overview" data-ajax="false">Übersicht</a> auf „Mit Tibber verbinden“ klicken.</li>
			</ol>
		</details>

		<h2 style="margin-top:20px">MQTT</h2>
		<label for="topic_prefix">MQTT-Topic-Präfix</label>
		<input type="text" id="topic_prefix" name="topic_prefix" value="<?= h($pcfg["topic_prefix"]) ?>">
		<p class="tdhint">Das MQTT Gateway abonniert <code><?= h($pcfg["topic_prefix"]) ?>/#</code> automatisch.</p>
		<label><input type="checkbox" name="retain" <?= $pcfg["retain"] ? "checked" : "" ?>> Werte beim Broker speichern (retain)</label>
		<label><input type="checkbox" name="publish_attributes" <?= $pcfg["publish_attributes"] ? "checked" : "" ?>> Geräte-Attribute mitsenden (Firmware, Konnektivität …)</label>
		<label><input type="checkbox" name="publish_units" <?= $pcfg["publish_units"] ? "checked" : "" ?>> Einheiten als eigene Topics senden (…_unit)</label>

		<button type="submit" data-inline="true" data-theme="b" data-icon="check">Speichern</button>
	</form>
</div>

<?php else: ?>
<!-- ======================================================= Log -->
<?php
$lines = is_readable($logfile) ? array_reverse(array_slice(file($logfile, FILE_IGNORE_NEW_LINES), -200)) : [];
$nprob = 0;
foreach ($lines as $l) if (preg_match('/ (FEHLER|WARN) /', $l)) $nprob++;
?>
<div class="tdbox">
	<h2>Log</h2>
	<?php if ($lines): ?>
	<p>
		<label style="display:inline"><input type="checkbox" id="tdonlyprob" data-role="none">
			Nur Fehler und Warnungen anzeigen (<?= $nprob ?>)</label>
	</p>
	<div class="tdlog" id="tdlog"><?php foreach ($lines as $l):
		$c = preg_match('/ FEHLER /', $l) ? "err" : (preg_match('/ WARN /', $l) ? "warn" : (preg_match('/ DEBUG /', $l) ? "dbg" : ""));
	?><div class="<?= $c ?>"><?= h($l) ?></div><?php endforeach; ?></div>
	<?php else: ?>
	<p>Noch keine Einträge.</p>
	<?php endif; ?>
	<p class="tdhint">Die letzten 200 Einträge, neueste oben. Vollständige Datei: <?= h($logfile) ?></p>
</div>
<?php endif; ?>

<script>
(function () {
	// Wertetabelle filtern
	var f = document.getElementById("tdfilter");
	if (f) f.addEventListener("input", function () {
		var q = f.value.toLowerCase();
		document.querySelectorAll("#tdvals tr").forEach(function (tr, i) {
			if (i > 0) tr.style.display = tr.textContent.toLowerCase().indexOf(q) >= 0 ? "" : "none";
		});
	});

	// Loxone-Namen kopieren (ohne HTTPS gibt es navigator.clipboard nicht)
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
		var ta = document.createElement("textarea");
		ta.value = text; ta.style.position = "fixed"; ta.style.opacity = "0";
		document.body.appendChild(ta); ta.select();
		try { document.execCommand("copy"); } finally { document.body.removeChild(ta); }
		return Promise.resolve();
	}
	document.querySelectorAll("button.tdcopy").forEach(function (b) {
		b.addEventListener("click", function () {
			copy(b.previousElementSibling.textContent).then(function () {
				b.textContent = "Kopiert"; b.classList.add("done");
				setTimeout(function () { b.textContent = "Kopieren"; b.classList.remove("done"); }, 1500);
			});
		});
	});

	// Log: nur Probleme
	var p = document.getElementById("tdonlyprob");
	if (p) p.addEventListener("change", function () {
		document.getElementById("tdlog").classList.toggle("problems", p.checked);
	});
})();
</script>

<?php
LBWeb::lbfooter();
?>
