#!/bin/sh
# Adım 1: Türkiye OSM verisini indirir, Gaziantep iline kırpar. Sonuç
# paylaşımlı bir Docker volume'a (/data) yazılır; adım 2 (process.sh, resmi
# osrm-backend imajıyla çalışır) bu veriyi car+foot profilleriyle işler.
#
# Çalıştırma:  docker compose run --rm osrm-download
# Yeniden çalıştırmak güvenlidir: tamamlanmış adımlar atlanır.
set -eu

DATA_DIR=/data
PBF_URL="${OSRM_SOURCE_PBF_URL:-https://download.geofabrik.de/europe/turkey-latest.osm.pbf}"
# Gaziantep ili + tampon (min_lon,min_lat,max_lon,max_lat). Nizip, İslahiye,
# Araban, Oğuzeli, Nurdağı gibi ilçe kampüslerini de kapsar.
BBOX="${OSRM_CLIP_BBOX:-36.3,36.5,38.2,37.8}"

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*"
}

mkdir -p "$DATA_DIR/car" "$DATA_DIR/foot"

if [ ! -f "$DATA_DIR/turkey-latest.osm.pbf" ]; then
    log "Türkiye OSM verisi indiriliyor: $PBF_URL"
    wget -q -O "$DATA_DIR/turkey-latest.osm.pbf.tmp" "$PBF_URL"
    mv "$DATA_DIR/turkey-latest.osm.pbf.tmp" "$DATA_DIR/turkey-latest.osm.pbf"
    log "İndirme tamamlandı: $(du -h "$DATA_DIR/turkey-latest.osm.pbf" | cut -f1)"
else
    log "Türkiye OSM verisi zaten mevcut, indirme atlanıyor."
fi

if [ ! -f "$DATA_DIR/gaziantep.osm.pbf" ]; then
    log "Gaziantep bölgesine kırpılıyor (bbox: $BBOX)"
    osmium extract --bbox "$BBOX" "$DATA_DIR/turkey-latest.osm.pbf" \
        -o "$DATA_DIR/gaziantep.osm.pbf.tmp" --output-format pbf --overwrite
    mv "$DATA_DIR/gaziantep.osm.pbf.tmp" "$DATA_DIR/gaziantep.osm.pbf"
    log "Kırpma tamamlandı: $(du -h "$DATA_DIR/gaziantep.osm.pbf" | cut -f1)"
else
    log "Kırpılmış Gaziantep verisi zaten mevcut, atlanıyor."
fi

log "Veri hazır. Şimdi profilleri işleyin: docker compose run --rm osrm-process"
