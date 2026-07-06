# Sadece OSM verisi indirme/kırpma için kullanılır (osmium-tool + wget).
# osrm-backend imajı Debian 9/stretch tabanlıdır ve apt depoları arşivlendiği
# için apt-get burada çalışmaz; bu yüzden güncel bir Debian tabanı kullanılır.
# Gerçek osrm-extract/partition/customize/routed adımları resmi osrm-backend
# imajıyla doğrudan (bu Dockerfile'sız) çalıştırılır — bkz. compose.yaml.
FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends osmium-tool wget ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY download.sh /usr/local/bin/download.sh
RUN chmod +x /usr/local/bin/download.sh

ENTRYPOINT ["/usr/local/bin/download.sh"]
