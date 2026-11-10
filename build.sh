#!/bin/bash
# Baut die Plugin-ZIP aus dem zuletzt committeten Stand (HEAD).
# Dateien mit "export-ignore" in .gitattributes landen nicht in der ZIP.
# Ergebnis: dist/TibberDataAPI-<Version>.zip
set -euo pipefail
cd "$(dirname "$0")"

version=$(git show HEAD:plugin.cfg | sed -n 's/^VERSION=//p')
pyversion=$(git show HEAD:bin/tibber_data_mqtt.py | sed -n 's/^VERSION = "\(.*\)"$/\1/p')
if [ "$version" != "$pyversion" ]; then
	echo "Versionen passen nicht zusammen: plugin.cfg=$version, tibber_data_mqtt.py=$pyversion" >&2
	exit 1
fi
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
	echo "Hinweis: Es gibt nicht committete Änderungen. Sie sind NICHT in der ZIP." >&2
fi

mkdir -p dist
out="dist/TibberDataAPI-$version.zip"
git archive --format=zip -o "$out" HEAD
echo "$out"
