#!/bin/bash
# Konfiguration und Tibber-Tokens vor dem Upgrade sichern
PTEMPDIR=$1
PDIR=$3
mkdir -p /tmp/${PTEMPDIR}_upgrade/config
cp -v -r $LBPCONFIG/$PDIR/ /tmp/${PTEMPDIR}_upgrade/config
exit 0
