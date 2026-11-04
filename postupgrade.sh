#!/bin/bash
# Gesicherte Konfiguration und Tibber-Tokens zurückspielen
PTEMPDIR=$1
PDIR=$3
cp -f -r /tmp/${PTEMPDIR}_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/
chmod 600 $LBHOMEDIR/config/plugins/$PDIR/tokens.json 2>/dev/null
rm -r /tmp/${PTEMPDIR}_upgrade
exit 0
