FROM redis:8.10.3-alpine@sha256:71885d8cc6e42f2bc0aa2eb853bfc4be200b5108a46ac50e117ae1df0531fb80 AS redis

FROM postgres:18.6-alpine@sha256:123b17998ccaf47e53b502e8c6a1e79afd8f579c6f3eec83c563da026f957395

RUN apk add --no-cache age=1.3.2-r1 rclone=1.75.2-r0 \
    && rm -f /usr/local/bin/gosu /usr/local/bin/docker-entrypoint.sh /usr/local/bin/docker-ensure-initdb.sh /usr/local/bin/docker-enforce-initdb.sh \
    && mkdir -p /backups \
    && chown 70:70 /backups \
    && chmod 700 /backups

COPY --from=redis /usr/local/bin/redis-cli /usr/local/bin/redis-cli
COPY --chown=root:root --chmod=755 fastgrid-backup.sh /usr/local/bin/fastgrid-backup

USER 70:70
WORKDIR /backups

ENTRYPOINT ["fastgrid-backup"]
CMD ["daemon"]
