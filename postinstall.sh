#!/bin/bash
PDIR=$3
chmod 600 $LBHOMEDIR/config/plugins/$PDIR/config.json
echo "<OK> Tibber Data API installiert. Bitte im Plugin Client-ID/Secret eintragen und mit Tibber verbinden."
exit 0
