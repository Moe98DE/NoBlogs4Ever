FROM restic/restic:0.18.0@sha256:4cf4a61ef9786f4de53e9de8c8f5c040f33830eb0a10bf3d614410ee2fcb6120
RUN apk add --no-cache mariadb-client bash
COPY scripts/backup.sh /scripts/backup.sh
COPY scripts/backup-freshness.sh /scripts/backup-freshness.sh
COPY scripts/restic-env.sh /scripts/restic-env.sh
ENTRYPOINT []
CMD ["/scripts/backup.sh"]
