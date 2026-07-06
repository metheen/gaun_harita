#!/bin/sh
# Adım 2: Kırpılmış Gaziantep verisini (osrm-download tarafından hazırlanır)
# hem araç (car) hem yaya (foot) profili için ayrı ayrı osrm-extract/
# partition/customize ile işler. Resmi osrm-backend imajı içinde, bu script
# bind-mount edilerek çalıştırılır (bkz. compose.yaml, osrm-process servisi) —
# ayrı bir imaj build etmeye gerek kalmaz.
#
# Çalıştırma:  docker compose run --rm osrm-process
# Yeniden çalıştırmak güvenlidir: tamamlanmış profiller atlanır.
set -eu

DATA_DIR=/data

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*"
}

if [ ! -f "$DATA_DIR/gaziantep.osm.pbf" ]; then
    log "HATA: $DATA_DIR/gaziantep.osm.pbf yok. Önce çalıştırın: docker compose run --rm osrm-download"
    exit 1
fi

for profile in car foot; do
    osrm_file="$DATA_DIR/$profile/gaziantep.osrm"
    if [ -f "$osrm_file" ]; then
        log "[$profile] zaten işlenmiş, atlanıyor."
        continue
    fi

    log "[$profile] işleniyor..."
    cp "$DATA_DIR/gaziantep.osm.pbf" "$DATA_DIR/$profile/gaziantep.osm.pbf"
    osrm-extract -p "/opt/${profile}.lua" "$DATA_DIR/$profile/gaziantep.osm.pbf"
    osrm-partition "$osrm_file"
    osrm-customize "$osrm_file"
    log "[$profile] hazır."
done

log "Tüm profiller hazır. osrm-car ve osrm-foot servislerini başlatabilirsiniz:"
log "  docker compose up -d osrm-car osrm-foot"
