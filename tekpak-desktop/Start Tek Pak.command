#!/bin/bash
# Tek Pak Desktop — Diamonds Outta Dirt
# Double-click (or run) to open Tek Pak as a desktop app window.
DIR="$(cd "$(dirname "$0")" && pwd)"
APP="$DIR/index.html"

if [ -d "/Applications/Google Chrome.app" ]; then
  open -na "Google Chrome" --args --app="file://$APP"
elif [ -d "/Applications/Microsoft Edge.app" ]; then
  open -na "Microsoft Edge" --args --app="file://$APP"
elif command -v google-chrome >/dev/null 2>&1; then
  google-chrome --app="file://$APP"
elif command -v chromium-browser >/dev/null 2>&1; then
  chromium-browser --app="file://$APP"
else
  open "$APP"
fi
