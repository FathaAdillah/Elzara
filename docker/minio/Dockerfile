# syntax=docker/dockerfile:1
FROM golang:1.25-alpine AS compiler
ENV CGO_ENABLED=0
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go install -p 4 github.com/minio/minio@RELEASE.2025-10-15T17-29-55Z \
    && go install -p 4 github.com/minio/mc@RELEASE.2025-08-13T08-35-41Z

FROM alpine:3.22
RUN apk add --no-cache ca-certificates curl \
    && addgroup -S minio && adduser -S -G minio minio \
    && mkdir /data && chown minio:minio /data
COPY --from=compiler /go/bin/minio /go/bin/mc /usr/local/bin/
USER minio
ENTRYPOINT ["minio"]
