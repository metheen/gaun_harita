#!/bin/sh
set -u

APP_DIR="${APP_DIR:-/var/www/html}"
SYNC_SCRIPT="${SYNC_SCRIPT:-scripts/sync_gaun_sources.php}"
SYNC_RUN_AT="${SYNC_RUN_AT:-05:10}"
SYNC_RUN_ON_START="${SYNC_RUN_ON_START:-false}"
TZ="${TZ:-Europe/Istanbul}"
export TZ

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*"
}

case "$SYNC_RUN_AT" in
    [01][0-9]:[0-5][0-9]|2[0-3]:[0-5][0-9])
        ;;
    *)
        log "HATA: SYNC_RUN_AT HH:MM formatında olmalı (00:00-23:59), gelen: $SYNC_RUN_AT"
        exit 1
        ;;
esac

if [ ! -f "$APP_DIR/$SYNC_SCRIPT" ]; then
    log "HATA: sync script bulunamadı: $APP_DIR/$SYNC_SCRIPT"
    exit 1
fi

run_sync() {
    log "GAÜN kaynak senkronizasyonu başladı: php $SYNC_SCRIPT --verbose"
    (
        cd "$APP_DIR" || exit 1
        php "$SYNC_SCRIPT" --verbose
    )
    status=$?

    if [ "$status" -eq 0 ]; then
        log "GAÜN kaynak senkronizasyonu tamamlandı"
    else
        log "GAÜN kaynak senkronizasyonu hata koduyla bitti: $status"
    fi
}

case "$SYNC_RUN_ON_START" in
    true|TRUE|1|yes|YES)
        run_sync
        ;;
    *)
        log "Başlangıç sync'i atlandı; günlük çalışma saati: $SYNC_RUN_AT $TZ"
        ;;
esac

while :; do
    today="$(date '+%Y-%m-%d')"
    now_epoch="$(date '+%s')"

    if ! target_epoch="$(date -d "$today $SYNC_RUN_AT:00" '+%s' 2>/dev/null)"; then
        log "HATA: çalışma zamanı hesaplanamadı"
        exit 1
    fi

    if [ "$target_epoch" -le "$now_epoch" ]; then
        if ! target_epoch="$(date -d "tomorrow $SYNC_RUN_AT:00" '+%s' 2>/dev/null)"; then
            log "HATA: sonraki çalışma zamanı hesaplanamadı"
            exit 1
        fi
    fi

    sleep_seconds=$((target_epoch - now_epoch))
    next_run="$(date -d "@$target_epoch" '+%Y-%m-%d %H:%M:%S %Z')"
    log "Sonraki GAÜN sync zamanı: $next_run (${sleep_seconds}s sonra)"

    sleep "$sleep_seconds"
    run_sync
done
